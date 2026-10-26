# Changelog

All notable changes to this project will be documented in this file. The changes should be categorized under one of
these sections: Added, Changed, Deprecated, Removed, Fixed or Security.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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


