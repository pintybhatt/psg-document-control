<?php

namespace App\Http\Middleware;

use App\Models\StorageEvent;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditDocumentStorageRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
            if ($response->getStatusCode() >= 400) {
                $this->record($request, $response->getStatusCode());

                return $this->sanitize($response);
            }

            return $response;
        } catch (Throwable $failure) {
            $status = match (true) {
                $failure instanceof ValidationException => 422,
                $failure instanceof AuthenticationException => 401,
                $failure instanceof ModelNotFoundException => 404,
                $failure instanceof AuthorizationException => 403,
                default => method_exists($failure, 'getStatusCode') ? $failure->getStatusCode() : 500,
            };
            $this->record($request, $status);
            if ($status >= 500) {
                report($failure);
            }
            $message = match (true) {
                $failure instanceof ValidationException => 'storageValidation',
                $status === 401 => 'rbacSessionExpired',
                $status === 403 => 'rbacForbidden',
                (bool) preg_match('/^(storage|rbac)[A-Za-z]+$/D', $failure->getMessage()) => $failure->getMessage(),
                default => 'rbacRequestFailed',
            };
            $body = ['message' => $message];
            if ($failure instanceof ValidationException) {
                $body['errors'] = $failure->errors();
            }

            return response()->json($body, $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
    }

    private function sanitize(Response $response): Response
    {
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(), true) ?? [];
        $message = match (true) {
            $status === 401 => 'rbacSessionExpired',
            $status === 403 => 'rbacForbidden',
            $status === 422 => 'storageValidation',
            $status < 500 && is_string($data['message'] ?? null) && (bool) preg_match('/^(storage|rbac)[A-Za-z]+$/D', $data['message']) => $data['message'],
            default => 'rbacRequestFailed',
        };
        $body = ['message' => $message];
        if ($status === 422 && is_array($data['errors'] ?? null)) {
            $body['errors'] = array_map(fn (array $messages): array => array_map(fn (string $value): string => preg_match('/^(storage|rbac)[A-Za-z]+$/D', $value) ? $value : 'storageValidation', $messages), $data['errors']);
        }

        return response()->json($body, $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function record(Request $request, int $status): void
    {
        $audit = new StorageEvent(['actor_id' => $request->user()?->id, 'action' => 'request.denied', 'reason' => (string) $status,
            'after' => ['route' => $request->route()?->getName(), 'record' => $request->route('record'), 'version' => $request->route('version')]]);
        if (! $audit->save()) {
            throw new \LogicException('Denied storage request audit persistence cancelled.');
        }
    }
}
