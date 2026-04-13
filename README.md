# PHPNomad CLI

Static analysis and introspection tools for PHPNomad projects. Reconstructs the full application boot sequence via AST parsing and produces structured indexes that AI agents and developers can query without reading source files directly.

## What it does

PHPNomad CLI scans a target project directory, walks the `Application -> Bootstrapper -> Initializer` chain, and extracts everything the boot sequence contributes: DI bindings, REST controllers, event listeners, commands, facades, and more. The result is a set of JSONL files in `.phpnomad/` that map the entire application surface.

### Index pipeline

1. **ClassIndex** scans all PHP files using nikic/php-parser and builds a class registry (FQCN, interfaces, traits, constructor params, parent class).
2. **BootSequenceWalker** finds Application classes by locating `new Bootstrapper()` calls, then parses the boot methods to extract the ordered Initializer list and direct container bindings.
3. **InitializerAnalyzer** parses each Initializer to extract contributions from every `Has*` interface: `HasClassDefinitions`, `HasControllers`, `HasListeners`, `HasEventBindings`, `HasCommands`, `HasMutations`, `HasTaskHandlers`, `HasTypeDefinitions`, `HasUpdates`, `HasFacades`.
4. **ControllerAnalyzer** resolves each registered controller class to extract endpoint paths, HTTP methods, and middleware/validation/interceptor capabilities.
5. **CommandAnalyzer** extracts CLI signatures and descriptions from registered command classes.
6. **DependencyResolver** builds recursive dependency trees by merging all binding sources and walking constructor params through the binding map.

### Output format

The index is written as JSONL files in `.phpnomad/` for token-efficient consumption:

```
.phpnomad/
  meta.json            # Summary counts
  classes.jsonl        # One class per line (FQCN, interfaces, traits, constructor params)
  initializers.jsonl   # One initializer per line (bindings, controllers, listeners, commands)
  applications.jsonl   # One application per line (boot sequence, pre/post bindings)
  controllers.jsonl    # One resolved controller per line (endpoint, method, capabilities)
  commands.jsonl       # One resolved command per line (signature, description)
  dependencies.jsonl   # One dependency tree per line (recursive resolution chain)
```

Each line is a self-contained JSON object. An AI agent can `grep "PayoutDatastore" .phpnomad/dependencies.jsonl` to find a specific dependency chain without loading 1000+ records.

## Commands

### `phpnomad index`

Scan a project and build the full index.

```bash
phpnomad index --path=/path/to/project
```

Produces the `.phpnomad/` directory with all JSONL files and reports summary stats.

### `phpnomad inspect:di`

Display the boot sequence and dependency injection bindings.

```bash
phpnomad inspect:di --path=/path/to/project
phpnomad inspect:di --path=/path/to/project --format=json
phpnomad inspect:di --path=/path/to/project --fresh  # Force re-index
```

Table output shows the full boot sequence tree with initializer contributions:

```
Application: Siren\SaaS\Application (saas/Application.php)

Pre-bootstrap bindings:
  [factory] Di\Interfaces\InstanceProvider
  [factory] Integration\Interfaces\DatabaseStrategy

Boot sequence (74 initializers, boot()):
  #1   Core\Bootstrap\CoreInitializer (vendor)    1 binding
  #2   SaaS\SaaSInitializer                       10 bindings
  #3   --- $additionalInitializers (dynamic) ---
  #4   MySql\Integration\MySqlInitializer (vendor) 8 bindings
  ...

Post-bootstrap bindings:
  [bind] SirenQueryStrategy -> QueryStrategy
  [factory] RedisConnection

Summary
  4 application(s)
  74 initializers
  244 bindings
  110 controllers
  23 commands
  151 listeners
  186 dependency trees
```

### `phpnomad inspect:routes`

List REST routes with their endpoints, methods, and capabilities.

```bash
phpnomad inspect:routes --path=/path/to/project
phpnomad inspect:routes --path=/path/to/project --format=json
```

```
GET (49)
  {base}                 Service\Rest\GetOrgs [middleware]
  {base}/{id}            Service\Rest\GetOrgById [middleware]
  /api-keys              Service\Rest\ListApiKeys [middleware]
  /webhooks              Service\Rest\ListWebhookSubscriptions [middleware]

POST (34)
  {base}                 Service\Rest\CreateNewCollaborator [middleware, validations, interceptors]
  {base}/bulk            Service\Rest\CollaboratorBulkAction [middleware, validations]
  ...

Summary
  110 controller(s)
  99 with middleware
  59 with validations
  39 with interceptors
  36 using WithEndpointBase
```

Controllers using the `WithEndpointBase` trait show `{base}` as a prefix since the base path is resolved at runtime from a configuration provider.

## Installation

```bash
composer require phpnomad/cli --dev
```

Or for system-wide use, symlink the binary:

```bash
ln -s /path/to/phpnomad/cli/bin/phpnomad ~/.local/bin/phpnomad
```

## Requirements

- PHP 8.2+
- nikic/php-parser ^5.0
- Target project must use the PHPNomad Bootstrapper pattern

## Design philosophy

**Read-side (introspection) is prioritized over write-side (scaffolding).** Introspection commands save tokens on every AI session by providing structured data about the project. Scaffolding saves tokens only when creating new code. Both matter, but introspection is the higher-leverage starting point.

**JSONL over nested JSON.** Each record type gets its own file with one JSON object per line. This lets agents grep for specific classes without parsing the entire index, and keeps token costs proportional to what's being queried.

**Static analysis, not runtime reflection.** The CLI parses PHP files via AST without executing them. No database connection, no bootstrap, no platform dependencies. It can index a WordPress plugin, a standalone app, or a test harness identically.

## License

MIT
