# Versioning and backward compatibility

KayraPHP follows [Semantic Versioning 2.0.0](https://semver.org). This document
says exactly what that means here, because "we follow SemVer" is only useful if
both sides agree on what counts as a break.

---

## What the numbers mean

```
MAJOR.MINOR.PATCH
```

| | When it changes |
|---|---|
| **MAJOR** | A documented public API changed in a way that can break your code |
| **MINOR** | New functionality, backward compatible |
| **PATCH** | Bug fixes and internal changes, backward compatible |

Before `1.0.0` the framework is explicitly unstable: **`0.x` minor releases may
break compatibility**, and this document describes the contract that begins at
`1.0.0`. Pin to an exact version until then.

---

## What is public API

Covered by the compatibility promise:

- Class, interface and enum names under `Kayra\`, and their namespaces
- **Public** and **protected** methods on non-`@internal` classes, including
  their parameter names (named arguments are part of the signature)
- Return types and thrown exception types of those methods
- Configuration keys in `config/*.php`, and the `.env` names they read
- Template directives and the `$loop` object
- Console command names, their arguments and options
- The service ids registered in the container
- Route, middleware and container behaviour that the test suite asserts

**Not** covered, and changeable in any release:

- Anything marked `@internal`
- Anything `private`, and `final` classes' internals
- The `Kayra\Tests\` namespace
- Exact wording of exception and log messages
- Generated file formats in `bootstrap/cache/` — always regenerate with
  `kayra optimize` after upgrading, never commit them
- The HTML of the debug error page
- Performance characteristics

---

## What counts as a break

**Breaking** (MAJOR only):

- Removing or renaming a public/protected method, class, or config key
- Adding a required parameter to a public/protected method
- Renaming a parameter (it breaks named arguments)
- Narrowing a parameter type, or widening a return type
- Adding a method to an interface that applications implement
- Changing a default in a way that alters behaviour for existing code
- Making a previously-permitted input throw

**Not breaking** (MINOR or PATCH):

- Adding a new class, method, or optional parameter *at the end* of a signature
- Adding a new configuration key with a default that preserves current behaviour
- Adding a method to a class (not an interface)
- Fixing behaviour that contradicted documentation — this is a bug fix, and the
  change note will say so
- Tightening a security default *is* treated as breaking if it can reject input
  that previously worked; it ships in a MAJOR, or behind a config flag in a MINOR

---

## Interfaces you may implement

An interface an application is expected to implement can never gain a method in
a MINOR release. These are:

- `Kayra\Session\SessionHandler`
- `Kayra\RateLimiter\RateLimiter`
- `Kayra\Runtime\RuntimeInterface`
- PSR interfaces (`MiddlewareInterface`, `RequestHandlerInterface`, ...)

Extending these means a MAJOR release, or a new interface alongside the old one.

Classes not designed for extension are `final`. That is deliberate: a `final`
class can be changed freely, so marking it is a promise about what the framework
will *not* break rather than a restriction imposed on you. If you need to extend
something that is `final`, open an issue — it usually means an interface is
missing.

---

## Deprecations

A feature is deprecated for at least one full MINOR cycle before removal:

1. Marked `#[\Deprecated]` with the replacement named in the message
2. Documented in the changelog under **Deprecated**
3. Removed in the next MAJOR, never sooner

Deprecated code keeps working, and keeps being tested, until it is removed.

---

## Security releases

Security fixes are backported to the current MAJOR and the one before it. A
security fix that requires a breaking change ships as a MINOR anyway, with the
break documented prominently — the alternative is leaving people exposed until
they can schedule a major upgrade, which is worse.

---

## Upgrading

Every MAJOR release ships an `UPGRADE.md` listing each break, its impact, and
the mechanical change required. Anything that can be automated is automated.

After any upgrade:

```bash
composer update
php kayra optimize:clear
php kayra optimize
php kayra doctor
php kayra test
```

`optimize:clear` first is not optional: the compiled artefacts embed the previous
version's service graph and route table, and a stale service plan produces
errors far from their cause.

---

## Supported PHP versions

KayraPHP supports the PHP versions that are in active or security support at the
time of the release. Dropping a PHP version is a MAJOR change.

| KayraPHP | PHP |
|---|---|
| 0.x | 8.5+ |
