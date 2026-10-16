# Навыки ИИ-агента в этом репозитории

**Навыки линейки shef.\*** — копия из [bx-shef/skills](https://github.com/bx-shef/skills)
(каталог `skills/`), на коммите из файла `SOURCE`. Файлы копии не правим:
замечания к навыку — отзывом (`shef-feedback`).

* обновить до свежего `main` — `.claude/skills/update.sh` (PR с новым `SOURCE`);
* сверить копию с источником — `.claude/skills/update.sh --verify` (так делает CI);
* целостность — `.claude/skills/sync.sh --check` (и `tests/skills_test.php`).

**Свои навыки репозитория** — `merge-pr`, `review-panel`, `shef-new-ai-provider` —
перенесены из [shef.toolsai](https://github.com/bx-shef/toolsai) как есть: порядок PR и
ревью тот же, а `shef-new-ai-provider` описывает, как этот модуль зовёт модель
shef.toolsai. Ссылки в них на документы shef.toolsai, которых здесь нет
(`deploy.md`, `copilot-chain-research.md`), — смотреть в его репозитории. Свои навыки
и служебные файлы перечислены в `LOCAL.MANIFEST`; правятся здесь, пересборка —
`sync.sh --local`.
