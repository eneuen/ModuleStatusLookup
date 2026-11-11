# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

Added:

- **The lookup line's number.** On install the module takes internal number 2300, or the first free number up to 2399, and registers it in the PBX's extensions as a module number. It then appears as "Module: Status Lookup (2300)" in the PBX's destination pickers (incoming routes, IVR menus) while the module is enabled, and is hidden while it is disabled. 2300 is used because the PBX numbers its own IVR menus, queues and applications from 2200100 up, so 2200 would be a prefix of them. A reinstall that keeps settings keeps the number; an uninstall without kept settings releases it, unless a route still points at it.
- **The module page** (administrators only) shows the lookup line's number.
