#!/usr/bin/env bash
# KlamPanter ep. 2 — reto "Solicitud de constancia" con modelos locales (aider + Ollama).
# Cada modelo trabaja en un git worktree limpio creado desde BASE_COMMIT, hasta 45 min sin intervención.
#
# Uso:  BASE_REPO=/ruta/proyecto BASE_COMMIT=<sha> bash correr_duelo.sh            # todos, en serie
#       BASE_REPO=... BASE_COMMIT=... bash correr_duelo.sh coder-7b                 # solo uno
#       REHACER=1 BASE_REPO=... BASE_COMMIT=... bash correr_duelo.sh coder-7b       # borra y repite uno
set -uo pipefail

: "${BASE_REPO:?Define BASE_REPO (proyecto Laravel base, con .env y public/build)}"
: "${BASE_COMMIT:?Define BASE_COMMIT (commit de la base, suite en verde)}"
D=$(cd "$(dirname "$0")" && pwd)                     # prompt.md, continua.md y settings viven junto al script
AUDITORIA=${AUDITORIA:-$D/../../auditoria/auditoria.php}
WT_ROOT=${WT_ROOT:-$(dirname "$BASE_REPO")}         # dónde se crean los worktrees duelo2-<slug>
OUT=$D/resultados
LIMITE_SEG=2700            # 45 min por modelo
MAX_RONDAS=10              # turnos de aider dentro de esos 45 min
export OLLAMA_API_BASE=${OLLAMA_API_BASE:-http://127.0.0.1:11434}
export BROWSER=true

# slug  modelo de aider (edita la lista con los tuyos; qwen35-duelo = tag local de Qwen 3.5 4.7B)
MODELOS=(   "qwen35-4b     ollama_chat/qwen35-duelo"
            "qwen35-9b     ollama_chat/qwen3.5:9b"
            "coder-7b      ollama_chat/qwen2.5-coder:7b"
            "coder-14b     ollama_chat/qwen2.5-coder:14b" )

mkdir -p "$OUT"
for fila in "${MODELOS[@]}"; do
  read -r slug modelo <<<"$fila"
  [[ $# -gt 0 && "$1" != "$slug" ]] && continue
  dir=$WT_ROOT/duelo2-$slug; log=$OUT/$slug
  if [[ -n "${REHACER:-}" ]]; then
    git -C "$BASE_REPO" worktree remove --force "$dir" 2>/dev/null; git -C "$BASE_REPO" branch -D "duelo2/$slug" 2>/dev/null; rm -rf "$log"
  fi
  [[ -f "$log/tiempo.txt" ]] && { echo "→ $slug ya corrido, se omite"; continue; }
  mkdir -p "$log"
  echo "════════ $slug ($modelo) ════════"

  # 1) proyecto limpio desde el commit base
  [[ -d $dir ]] || git -C "$BASE_REPO" worktree add -f -b "duelo2/$slug" "$dir" "$BASE_COMMIT" || exit 1
  cd "$dir"
  cp "$BASE_REPO/.env" .env
  sed -i -E 's#^DB_CONNECTION=.*#DB_CONNECTION=sqlite#; s#^DB_DATABASE=.*#DB_DATABASE='"$dir"'/database/database.sqlite#' .env
  sed -i -E '/^DB_(HOST|PORT|USERNAME|PASSWORD)=/d' .env
  touch database/database.sqlite
  composer install -q --no-interaction && php artisan key:generate -q --force && php artisan migrate -q --force
  rm -rf public/build && cp -r "$BASE_REPO/public/build" public/ 2>/dev/null   # assets compilados (no están en git)
  printf '.aider*\n' >> "$(git rev-parse --git-path info/exclude)"   # en un worktree .git es archivo

  # 1b) la base debe estar 100% verde antes de empezar
  base=$(php artisan test --compact 2>&1 | grep -E "Tests:" | tail -1)
  echo "Base: $base" | tee "$log/base.txt"
  if echo "$base" | grep -q failed; then echo "✗ La base no está verde; revisa $dir antes de seguir"; exit 1; fi
  git add -A >/dev/null; git commit -qm "duelo2: base lista" --allow-empty

  # 2) el agente: varias rondas hasta 45 min, sin intervención humana
  ollama stop "${modelo#ollama_chat/}" 2>/dev/null
  nvidia-smi --query-gpu=index,memory.used,memory.total --format=csv > "$log/gpu_antes.csv"
  provider=$(ls app/Providers/Filament/*.php 2>/dev/null | head -1)
  cat > "$log/agente.sh" <<AG
#!/usr/bin/env bash
cd "$dir"; fin=\$(( \$(date +%s) + $LIMITE_SEG )); msg="$D/prompt.md"
for r in \$(seq 1 $MAX_RONDAS); do
  resta=\$(( fin - \$(date +%s) )); (( resta <= 30 )) && break
  echo; echo "━━━━━━━━ RONDA \$r · quedan \$((resta/60)) min ━━━━━━━━"
  timeout --foreground \$resta aider --model $modelo --model-settings-file $D/.aider.model.settings.yml \\
    --yes-always --no-auto-commits --no-show-model-warnings --no-check-update --no-analytics \\
    --no-show-release-notes --no-detect-urls --no-gitignore --restore-chat-history \\
    --lint-cmd 'php: php -l' --auto-lint --test-cmd 'php artisan test --compact' --auto-test \\
    --message-file "\$msg" app/Models/User.php $provider
  msg="$D/continua.md"
  # termina antes si ya existe el módulo y la suite está verde
  if [[ -f app/Models/Solicitud.php ]] && php artisan test --compact 2>&1 | grep -q "Tests:.*passed" \\
     && ! php artisan test --compact 2>&1 | grep -q "failed"; then echo "✓ suite verde, el agente terminó"; break; fi
done
AG
  inicio=$(date +%s)
  if command -v asciinema >/dev/null; then asciinema rec -q --overwrite "$log/agente.cast" -c "bash $log/agente.sh"; else bash "$log/agente.sh" | tee "$log/agente.log"; fi
  fin=$(date +%s)
  ollama ps > "$log/ollama_ps.txt"
  echo "$(( (fin-inicio)/60 )) min $(( (fin-inicio)%60 )) s" > "$log/tiempo.txt"

  # 3) resultados objetivos
  git status --short > "$log/archivos.txt"
  git add -A; git diff --cached --stat > "$log/diff_stat.txt"; git diff --cached > "$log/cambios.diff"
  for f in $(git diff --cached --name-only --diff-filter=AM -- '*.php'); do php -l "$f"; done > "$log/php_l.txt" 2>&1
  php artisan test --compact > "$log/tests.txt" 2>&1
  php "$AUDITORIA" "$dir" > "$log/auditoria.txt" 2>&1
  git commit -qm "duelo2: entrega de $slug"
  echo "→ $slug listo: $(cat "$log/tiempo.txt") · $(sed 's/\x1b\[[0-9;]*m//g' "$log/auditoria.txt" | grep -o 'RESULTADO: [0-9] / 5')"
done
echo "Todo listo. Resultados en $OUT"
