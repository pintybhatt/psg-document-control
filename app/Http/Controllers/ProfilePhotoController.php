<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The signed-in user manages only their own photo. Files live on the private disk and are never publicly addressable. */
class ProfilePhotoController extends Controller
{
    private const DISK = 'local';

    public function show(Request $request): StreamedResponse
    {
        $path = $request->user()->profile_photo_path;
        abort_if($path === null || ! Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=6000,max_height=6000']]);
        $user = $request->user();
        $file = $request->file('photo');
        $path = $file->storeAs('profile-photos/'.$user->id, Str::uuid().'.'.$file->extension(), self::DISK);
        $previous = $user->profile_photo_path;
        $user->forceFill(['profile_photo_path' => $path])->save();
        if ($previous !== null) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return response()->json(['photoUrl' => $user->profilePhotoUrl()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->profile_photo_path !== null) {
            Storage::disk(self::DISK)->delete($user->profile_photo_path);
            $user->forceFill(['profile_photo_path' => null])->save();
        }

        return response()->json(['photoUrl' => null]);
    }
}
