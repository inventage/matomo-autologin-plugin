# Changelog

All notable changes to this project will be documented in this file. The changes should be categorized under one of
these sections: Added, Changed, Deprecated, Removed, Fixed or Security.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.3.0

### Security

- Fixed an authentication bypass. Anyone who could reach Matomo directly could send `matomo-username` and
  `matomo-role: superuser` headers and get a superuser session. AutoLogin now rejects requests unless the plugin is
  enabled, the request comes from a configured trusted proxy and carries the shared secret header.
- The `superuser` role header, and signing in as an existing superuser, now require the new "Allow superuser role"
  setting (off by default).
- `module=AutoLogin` no longer inherits the actions of the Login controller. Before this, `action=logme` signed in
  any non-superuser without checking the password when `login_allow_logme` was enabled.
- Removed `Piwik\Plugins\AutoLogin\API`. Matomo exposed its unchecked `setSuperUserAccess`, `setUserAccess` and
  `resetUserAccess` methods through the HTTP API as `AutoLogin.*`.

### Changed

- Breaking: AutoLogin is disabled after installing or updating. Set `enabled`, `sharedSecret` and
  `trustedProxies` in the `[AutoLogin]` section of `config.ini.php` to turn it on.
- `enabled`, `sharedSecret`, `trustedProxies` and `allowSuperUserRole` are read from `config.ini.php`
  instead of the database, so they can be set with `console config:set` when deploying and cannot be
  changed from the web UI. `trustedProxies` accepts commas as well as newlines.
- A `matomo-role` header other than `superuser` now removes superuser access from the user. AutoLogin refuses to
  remove it from the last superuser.
- Requests with underscore variants of the `matomo-*` headers (e.g. `matomo_username`) are rejected.
- Error messages no longer include stack traces or the list of site IDs.

### Fixed

- Headers are read case-insensitively, so AutoLogin also works under PHP-FPM and with proxies that send
  `Matomo-Username` instead of `matomo-username`.

## 0.2.1

### Fixed

- Compatibility with Matomo 5.10.0 and later: `setUserAccess()` and `setSuperUserAccess()` now declare the
  `void` return type that `Piwik\Plugins\UsersManager\API` gained in Matomo 5.10.0. Without it PHP aborts with a
  "must be compatible with" fatal error and every request to `index.php?module=AutoLogin` returns HTTP 500.
- Compatibility with PHP 8.4: optional constructor parameters are explicitly nullable instead of implicitly.
- `RolesProvider` and `CapabilitiesProvider` constructor type hints pointed at the plugin namespace instead of
  `Piwik\Access`.

## 0.2.0

### Changed

- Compatibility with Matomo version 5

## 0.1.2
### Fixed
- Fix relative image path in ReadMe by adding the absolute path.

## 0.1.1
### Fixed
- Fix relative image path in ReadMe. (broken)

## 0.1.0

### Initially added:
- Automatic login & registration with http headers.
- Group management in system settings.


