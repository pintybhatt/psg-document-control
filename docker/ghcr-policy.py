#!/usr/bin/env python3
"""Read-only GHCR metadata policy. Never create a package, publish, or change visibility."""
import argparse
import json
import os
import re
import urllib.error
import urllib.request


PACKAGE = 'https://api.github.com/users/pintybhatt/packages/container/psg-document-control'


class PolicyError(ValueError):
    """Only fixed, credential-free policy messages may be displayed."""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, new_url):
        return None


def metadata(url, token):
    request = urllib.request.Request(url, headers={'Authorization': 'Bearer '+token,
                                                 'Accept': 'application/vnd.github+json',
                                                 'User-Agent': 'XPPL-DCMS-release-policy',
                                                 'X-GitHub-Api-Version': '2022-11-28'})
    with urllib.request.build_opener(NoRedirect).open(request, timeout=30) as response:
        return json.load(response)


def check(token, new_tag=None, allow_initial=False):
    if not token:
        raise PolicyError('GitHub token is required; never pass it as an argument.')
    if new_tag and not re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+(?:-rc\.[0-9]+)?-[a-f0-9]{40}', new_tag):
        raise PolicyError('Version plus full source SHA is required.')
    try:
        package = metadata(PACKAGE, token)
    except urllib.error.HTTPError as error:
        if error.code == 404 and allow_initial and new_tag:
            # GHCR defaults new packages to private. This is an explicit bootstrap
            # exception, not proof of absence; visibility must be rechecked after push.
            return 'Initial private-package creation requires the recorded owner approval.'
        raise
    if package.get('visibility') != 'private' or package.get('name') != 'psg-document-control':
        raise PolicyError('The DCMS package must already be private; no visibility change attempted.')
    if new_tag:
        page = 1
        while True:
            versions = metadata(PACKAGE+'/versions?per_page=100&page='+str(page), token)
            if any(new_tag in version.get('metadata', {}).get('container', {}).get('tags', []) for version in versions):
                raise PolicyError('Release tag already exists; choose a new reviewed version.')
            if len(versions) < 100:
                break
            page += 1
    return 'Private GHCR package policy passed.'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--new-tag')
    parser.add_argument('--allow-initial-private', action='store_true')
    args = parser.parse_args()
    try:
        print(check(os.environ.get('GH_TOKEN', ''), args.new_tag, args.allow_initial_private))
    except (ValueError, OSError) as error:
        # Do not echo HTTP bodies, credentials, URLs supplied by a server or headers.
        message = str(error) if isinstance(error, PolicyError) else 'GitHub metadata check unavailable; publication refused.'
        parser.exit(1, 'STOP: '+message+'\n')


if __name__ == '__main__':
    main()
