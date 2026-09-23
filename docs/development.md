# Работа с проектом

## Ежедневный цикл

1. Прочитайте `AGENTS.md`, проверьте рабочее дерево. Не коммитьте и не отправляйте изменения без явной команды.
2. Выполните `php artisan app:doctor`. При недоступности PostgreSQL запустите сервис Herd и проверьте только параметры собственного `.env`.
3. Запустите `composer dev`: приложение `http://localhost:8000`, Vite, worker и scheduler.
4. Меняйте одну вертикаль и прогоняйте целевые тесты. После PHP-изменений runtime перезапускайте worker: `php artisan queue:restart` и заново `composer dev`, если процесс был завершён.
5. Перед передачей результата выполните `composer ci:check` и `git diff --check`.

`composer setup` сохраняет существующий APP_KEY, не сбрасывает БД и использует lock-файлы. Базы создаются отдельно. Не используйте `migrate:fresh` на базе приложения.

## Карта

| Изменение                                 | Где                                                                    |
| ----------------------------------------- | ---------------------------------------------------------------------- |
| Системные инструкции и инструменты агента | `app/Ai/Agents/WorkspaceAgent.php`, `app/Ai/Tools`                     |
| Разрешённые действия и validation         | `app/Ai/ToolBroker.php`                                                |
| Демо / настоящий SDK                      | `app/Ai/Runtime`                                                       |
| Запуск, завершение, отмена и approvals    | `app/Actions/AgentRuns`, `app/Jobs/ExecuteAgentRun.php`                |
| HTTP и права                              | `app/Http`, `app/Policies`                                             |
| UI                                        | `resources/js/pages/runs`, `resources/js/hooks/use-permissions.ts`     |
| Админпанель, пользователи, роли           | `app/Filament/Resources`                                               |
| Каталог разрешений и назначение доступа   | `app/Enums/AccessPermission.php`, `app/Actions/Access`, `app/Policies` |
| Конфигурация и лимиты                     | `config/agents.php`, `config/ai.php`                                   |
| Regression tests                          | `tests/Feature/Agents`                                                 |
| Правила coding-агентов                    | `AGENTS.md`, `CLAUDE.md`                                               |

Filament использует собственный Livewire UI; пользовательские страницы — React/Inertia. Не вставляйте React-компоненты в Filament или Livewire-компоненты в Inertia-страницы.

## MCP и skills

```bash
composer boost:refresh
php artisan boost:mcp
```

Вторая команда — STDIO-сервер, его запускает MCP-клиент; это не HTTP endpoint. `.codex/config.toml` и `.mcp.json` рассчитаны на запуск из корня репозитория с `php` в PATH. После изменения конфигурации начните новую задачу клиента. Только trusted project configuration загружается в Codex.

Boost имеет локальные инструменты с широкими правами разработчика. Не подключайте его к пользовательскому AI-агенту и не публикуйте сервер в интернет. Он является dev dependency; runtime-инструменты ограничены приложением отдельно.

Сгенерированные `resources/js/actions`, `routes` и `wayfinder` игнорируются Git; `npm run build` и `composer ci:check` генерируют их. Не правьте их вручную. Вызывайте `php artisan wayfinder:generate --with-form` перед отдельным запуском TypeScript, если изменились routes.

## Тестирование и CI

PHPUnit принудительно задаёт `APP_ENV=testing`, `DB_CONNECTION=pgsql`, `DB_DATABASE=hackalem_testing`, очищает DB_URL и OPENAI_API_KEY. Базовый TestCase дополнительно проверяет имя базы до database reset. Остальные параметры подключения наследуются из `.env` / `.env.testing` / CI.

Не запускайте test suites параллельно на одной `hackalem_testing`: миграции и транзакции будут мешать друг другу. CI поднимает PostgreSQL 18 как отдельный service container; workflow сейчас только подготовлен локально и не отправлен на GitHub.

SDK fake доказывает интеграцию и запись usage, но не делает реальный tool loop. Поэтому `ToolBroker` и `ResolveApproval` тестируются непосредственно. DemoRuntime проходит через тот же broker. Любая unfaked попытка live-вызова из runtime в testing завершается ошибкой до сети.

## Подготовка к production

Нужны обычная настройка HTTPS/APP_DEBUG=false, почты, закрытого PostgreSQL, supervision для worker, scheduler, секретов и резервных копий. `app:user` намеренно работает только локально; production-доступ выдавайте своим процессом управления пользователями. Сборка и тесты этой задачи не являются deployment.

## Работа с симулятором

- Датасет: `database/seeders/data/astana-v1.json`, `SimulationDatasetSeeder`. Для изменения опубликованных данных создайте новую версию, не перезаписывайте старую.
- Математика и правила: `app/Actions/Simulations`; страницы и общие компоненты: `resources/js/pages/scenarios`, `resources/js/components/simulation`.
- AI: `ScenarioAnalysisAgent`, `ScenarioChatAgent`, `ScenarioFacts`, `CreateScenarioRun`; сценарные инструменты проходят через общий `ToolBroker`.
- Целевые проверки: `php artisan test --compact --filter='SimulationCalculationTest|SimulationScenarioTest|ScenarioAiTest'`. Затем `composer ci:check`.
- При локальной проверке используйте «Пример из задания». После смены `AGENT_DRIVER` выполните `php artisan config:clear` и перезапустите worker. `demo` выполняет тот же жизненный цикл без API; `laravel` использует ключ только на сервере.
- `composer setup` / `app:prepare` теперь загружают городской датасет вместе с демо-доступом. Существующие данные сохраняются.
