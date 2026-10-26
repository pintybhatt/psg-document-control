<?php

namespace App\Http\Middleware;

use App\Models\StorageEvent;
use Closure;
use Illuminate\Http\Request;
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
            }

            return $response;
        } catch (Throwable $failure) {
            $this->record($request, method_exists($failure, 'getStatusCode') ? $failure->getStatusCode() : 500);
            throw $failure;
        }
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
