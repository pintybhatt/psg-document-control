<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">
        <title>DCMS · Phonesack Group</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        @php
            $navigation = collect($pages)->map(fn (string $path, string $key): array => [
                'key' => $key,
                'href' => route('dcms.'.$key, [], false),
            ])->values();
        @endphp
        <div id="dcms-app" data-props="{{ json_encode([
            'page' => $page,
            'navigation' => $navigation,
            'context' => ['company' => ['id' => null, 'name' => 'Phonesack Group'], 'site' => null],
            'initialLanguage' => session('language', auth()->user()?->preferred_language ?? 'en'),
            'auth' => [
                'loginUrl' => route('login.store', [], false),
                'logoutUrl' => route('logout', [], false),
                'csrfToken' => csrf_token(),
                'email' => old('email', ''),
                'errors' => collect($errors->messages())->map(fn (array $messages): string => $messages[0]),
            ],
        ]) }}"></div>
        <noscript>
            <p>Enable JavaScript to open DCMS. / ກະລຸນາເປີດ JavaScript ເພື່ອໃຊ້ DCMS.</p>
        </noscript>
    </body>
</html>
