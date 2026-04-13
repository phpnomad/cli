# PHPNomad CLI

Scaffolding, introspection, and validation tools for PHPNomad projects.

## Status

Early development. Command surface is being built up incrementally.

## Design Philosophy

PHPNomad CLI is designed to minimize the token cost of working with PHPNomad projects from AI agents. Commands output structured data that agents can consume without having to read source files directly.

**Read-side (introspection) is prioritized over write-side (scaffolding):** introspection commands save tokens on every session; scaffolding saves tokens only on new work. Both matter, but introspection is the higher-leverage starting point.

## Command Categories

- **Introspection** (`inspect:*`) — output structured data about an existing PHPNomad project
- **Tracing** (`trace:*`) — simulate request paths and event dispatches through the framework
- **Validation** (`validate`) — surface convention violations in a form autonomous agents can consume
- **Scaffolding** (`make:*`) — generate real PHP files committed to source control, no compile step

## Commands

### Introspection

- `inspect:routes` — list REST routes with their middleware chains (not yet implemented)

More commands incoming as the tool evolves.

## Installation

Not yet published. Local development only.

## License

MIT
