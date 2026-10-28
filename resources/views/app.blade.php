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
            'workspace' => ['contexts' => route('api.v1.incoming.contexts', [], false), 'dashboard' => route('api.v1.dashboard', [], false)],
            'auth' => [
                'userId' => auth()->id(),
                'loginUrl' => route('login.store', [], false),
                'logoutUrl' => route('logout', [], false),
                'csrfToken' => csrf_token(),
                'email' => old('email', ''),
                'errors' => collect($errors->messages())->map(fn (array $messages): string => $messages[0]),
            ],
            'reports' => ['endpoint' => route('api.v1.reports.index', [], false)],
            'search' => ['endpoint' => route('api.v1.document-search', [], false)],
            'controlled' => ['endpoint' => route('api.v1.controlled.index', [], false), 'contexts' => route('api.v1.controlled.contexts', [], false)],
            'outgoing' => ['incoming' => route('api.v1.incoming.index', [], false), 'endpoint' => route('api.v1.outgoing.index', [], false), 'contexts' => route('api.v1.outgoing.contexts', [], false)],
            'incoming' => ['endpoint' => route('api.v1.incoming.index', [], false), 'contexts' => route('api.v1.incoming.contexts', [], false)],
            'administration' => [
                'organization' => route('api.v1.organization.index', ['kind' => 'companies'], false),
                'history' => route('api.v1.administration.history', [], false),
                'numbering' => route('api.v1.numbering.index', [], false),
                'templates' => route('api.v1.templates.index', [], false),
                'contexts' => route('api.v1.authorization.contexts', [], false),
                'roles' => route('api.v1.roles.index', [], false),
                'permissions' => route('api.v1.permissions', [], false),
                'assignments' => route('api.v1.assignments.index', [], false),
                'users' => route('api.v1.assignment-users', [], false),
                'memberships' => route('api.v1.memberships.index', [], false),
                'membershipUnits' => route('api.v1.membership-units', [], false),
                'membershipUsers' => route('api.v1.membership-users', [], false),
                'masterData' => [
                    'categories' => route('api.v1.master-data.index', ['kind' => 'categories'], false),
                    'types' => route('api.v1.master-data.index', ['kind' => 'types'], false),
                    'registers' => route('api.v1.master-data.index', ['kind' => 'registers'], false),
                    'classifications' => route('api.v1.master-data.classifications', [], false),
                ],
            ],
        ]) }}"></div>
        <noscript>
            <p>Enable JavaScript to open DCMS. / ກະລຸນາເປີດ JavaScript ເພື່ອໃຊ້ DCMS.</p>
        </noscript>
    </body>
</html>
