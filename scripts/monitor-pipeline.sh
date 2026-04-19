#!/bin/bash
# Monitor a pipeline run - lightweight script to avoid token bloat
# Usage: ./scripts/monitor-pipeline.sh <run_id> [max_iterations]

RUN_ID=${1:-$(php -r "
require 'vendor/autoload.php';
\app\WorkspaceResolver::switchDatabase('dev');
\$r = \app\Bean::findOne('pipelineruns', 'ORDER BY id DESC');
echo \$r->id ?? 0;
" 2>/dev/null)}

MAX=${2:-60}

echo "Monitoring run $RUN_ID..."

for i in $(seq 1 $MAX); do
  OUTPUT=$(php -r "
    require 'vendor/autoload.php';
    \app\WorkspaceResolver::switchDatabase('dev');
    \$jobs = \app\Bean::find('pipelinejobs', 'run_id = ?', [$RUN_ID]);
    \$j = [];
    foreach (\$jobs as \$job) { \$j[] = \$job->step_name.'='.\$job->status; }
    \$r = \app\Bean::load('pipelineruns', $RUN_ID);
    echo implode(', ', \$j) . '|' . \$r->status . ' ' . \$r->completed_steps . '/' . \$r->total_steps;
  " 2>/dev/null)

  JOBS=$(echo "$OUTPUT" | cut -d'|' -f1)
  RUN=$(echo "$OUTPUT" | cut -d'|' -f2)
  SESSIONS=$(tmux list-sessions 2>/dev/null | grep -c "PIPE-$RUN_ID" || echo 0)

  echo "[$i] $JOBS | Run: $RUN | Sessions: $SESSIONS"

  [[ "$RUN" == "completed"* ]] || [[ "$RUN" == "failed"* ]] && { echo "Done!"; exit 0; }
  sleep 3
done
echo "Timeout after $MAX iterations"
