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
7. **TableAnalyzer** extracts table schemas from classes extending the Table abstract, including column types, factory patterns, and foreign key references.
8. **EventAnalyzer** resolves event classes to extract their string IDs and payload properties.
9. **GraphQLTypeAnalyzer** extracts SDL definitions and resolver mappings from GraphQL type definitions.
10. **FacadeAnalyzer** resolves facade classes to extract which interface each proxies via `abstractInstance()`.
11. **TaskHandlerAnalyzer** links task handler classes to their task classes and extracts the task's runtime ID.
12. **MutationAnalyzer** resolves mutation handlers, detecting adapter trait usage and action mappings.
13. **DependencyGraphBuilder** builds a unified relationship graph from all indexed data and inverts it for reverse lookups. Identifies orphan classes with no relationships.

### Output format

The index is written as JSONL files in `.phpnomad/` for token-efficient consumption:

```
.phpnomad/
  meta.json              # Summary counts
  classes.jsonl          # One class per line (FQCN, interfaces, traits, constructor params)
  initializers.jsonl     # One initializer per line (bindings, controllers, listeners, commands)
  applications.jsonl     # One application per line (boot sequence, pre/post bindings)
  controllers.jsonl      # One resolved controller per line (endpoint, method, capabilities)
  commands.jsonl         # One resolved command per line (signature, description)
  dependencies.jsonl     # One dependency tree per line (recursive resolution chain)
  tables.jsonl           # One table per line (name, columns, types, foreign keys)
  events.jsonl           # One event per line (event ID, payload properties)
  graphql-types.jsonl    # One GraphQL type per line (SDL, resolvers)
  facades.jsonl          # One facade per line (proxied interface)
  task-handlers.jsonl    # One handler per line (task class, task ID)
  mutations.jsonl        # One mutation handler per line (actions, adapter info)
  dependency-map.jsonl   # What each class depends on (all relationship types)
  dependents-map.jsonl   # What depends on each class (reverse lookup)
  orphans.jsonl          # Classes with no relationships in either direction
```

Each line is a self-contained JSON object. An AI agent can `grep "PayoutDatastore" .phpnomad/dependents-map.jsonl` to find everything that depends on an interface without reading a single source file.

The **dependency-map** and **dependents-map** are a unified relationship graph covering all edge types: constructor injection, interface implementation, inheritance, trait usage, event listeners, task handlers, facade proxies, DI bindings, and mutation adapters. The dependency-map shows outbound edges (what does X depend on?), the dependents-map shows inbound edges (what depends on X?). The **orphans** file lists classes with no relationships in either direction, flagging candidates for removal.

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
  39 tables
  82 events
  29 facades
  1 task handlers
  0 mutations
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

### `phpnomad make`

Generate PHP files from a recipe spec and register them in the project.

```bash
phpnomad make --from=listener '{"name":"SendWelcomeEmail","event":"App\\Events\\UserCreated","initializer":"App\\AppInit"}'
phpnomad make --from=event '{"name":"UserCreated","eventId":"user.created"}'
phpnomad make --from=command '{"name":"DeployCommand","signature":"deploy {env}","description":"Deploy to env","initializer":"App\\AppInit"}'
phpnomad make --from=controller '{"name":"GetUsers","method":"GET","endpoint":"/users","initializer":"App\\AppInit"}'
```

The `--from` flag accepts a built-in recipe name (`listener`, `event`, `command`, `controller`) or a path to a custom JSON spec file. Variables are passed as a JSON object argument.

Each recipe generates the PHP file(s) with proper namespace, interface implementations, and TODO markers, then automatically registers the new class in the specified initializer via AST mutation. If the initializer doesn't have the required method (e.g. `getListeners()`), the scaffolder creates it and adds the corresponding `Has*` interface.

Custom recipes can create multiple files and registrations in a single spec, enabling full feature scaffolding (e.g., a datastore with its interface, CRUD controllers, events, and listeners) from one command.

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

## Token efficiency

The index exists to save tokens. Instead of reading source files and grepping across the codebase, an AI agent can answer structural questions with a single grep against a JSONL file.

Benchmarked against a real project (Siren, 1,019 classes, 69 events, 27 tables):

| Query | Index | Raw source | Savings |
|---|---|---|---|
| What does `AllocateDistribution` depend on? | 1.1 KB | 75 KB | **67x** |
| What injects `EventStrategy`? | 7 KB | 394 KB | **54x** |
| What implements `DataModel`? (36 classes) | 3 KB | 47 KB | **14x** |
| Boot sequence + initializer contributions | 10 KB | 315 KB | **32x** |
| All DI bindings with resolution chains | 7 KB | 93 KB | **13x** |
| All task handlers with task mappings | 0.3 KB | 37 KB | **109x** |
| All events with IDs (69 events) | 23 KB | 73 KB | **3x** |
| All table schemas (27 tables) | 19 KB | 42 KB | **2x** |
| Unreferenced classes (50 orphans) | 6 KB | N/A | impossible without index |

"Index" is the bytes read from a single grep or cat on the relevant JSONL file. "Raw source" is the bytes an agent would need to read from PHP files to derive the same answer. At ~4 bytes per token, the reverse lookup on `EventStrategy` drops from ~98,000 tokens to ~1,750 tokens.

The savings are largest for reverse lookups (what depends on X?) because without the index, the agent must grep and read every file in the project. Forward lookups (what does X depend on?) are still faster because the index pre-resolves DI bindings that would otherwise require tracing through multiple initializer files.

## Design philosophy

**Read-side (introspection) is prioritized over write-side (scaffolding).** Introspection commands save tokens on every AI session by providing structured data about the project. Scaffolding saves tokens only when creating new code. Both matter, but introspection is the higher-leverage starting point.

**JSONL over nested JSON.** Each record type gets its own file with one JSON object per line. This lets agents grep for specific classes without parsing the entire index, and keeps token costs proportional to what's being queried.

**Static analysis, not runtime reflection.** The CLI parses PHP files via AST without executing them. No database connection, no bootstrap, no platform dependencies. It can index a WordPress plugin, a standalone app, or a test harness identically.

## License

MIT
