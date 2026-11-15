# Composer's platform PHP is pinned to 8.4.1

- **Context.** Without a pin, Dependabot resolves as PHP `8.4` (8.4.0), which the locked Symfony 8.1 components and PHPUnit 13.3 refuse (`php >=8.4.1`), so every framework-group update (Laravel, Filament, Livewire) failed in the Dependabot job without opening a pull request.
- **Decision.** Proposed by the session on 2026-10-01 (`4bee8d1`, in the backlog) and accepted by the owner with the merge of #NN, which adds this file (the owner approves that pull request): `composer.json` pins `config.platform.php` to `8.4.1`, the floor the lock already demands.
- **Consequence.** It cannot strand a machine that installs today. Raise it deliberately when a package needs a newer patch, and never above the PHP the development machine runs.
- **To reverse.** Delete `config.platform.php` from `composer.json` (line 95), or raise it. No test pins it; Dependabot's framework-group updates would fail again without a pull request.
