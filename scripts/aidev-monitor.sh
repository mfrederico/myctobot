#!/bin/bash
# AI Dev Job Monitor
# Usage:
#   aidev-monitor.sh list     - List all jobs
#   aidev-monitor.sh tail JOB - Tail log for specific job
#   aidev-monitor.sh info JOB - Show job info
#   aidev-monitor.sh watch    - Watch all running jobs

JOBS_DIR="/tmp"

list_jobs() {
    echo "=== AI Developer Jobs ==="
    echo ""
    printf "%-36s %-10s %-20s %s\n" "JOB ID" "STATUS" "STARTED" "LOG FILE"
    printf "%-36s %-10s %-20s %s\n" "------" "------" "-------" "--------"

    for dir in $JOBS_DIR/aidev-job-*; do
        if [ -d "$dir" ]; then
            job_id=$(basename "$dir" | sed 's/aidev-job-//')
            info_file="$dir/session-info.json"
            log_file="$dir/session.log"

            if [ -f "$info_file" ]; then
                status=$(grep -o '"status"[[:space:]]*:[[:space:]]*"[^"]*"' "$info_file" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')
                started=$(grep -o '"started_at"[[:space:]]*:[[:space:]]*"[^"]*"' "$info_file" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')
            else
                status="unknown"
                started="unknown"
            fi

            printf "%-36s %-10s %-20s %s\n" "$job_id" "$status" "$started" "$log_file"
        fi
    done

    echo ""
    echo "Commands:"
    echo "  $(basename $0) tail <job_id>  - Follow job log"
    echo "  $(basename $0) info <job_id>  - Show job details"
    echo "  $(basename $0) watch          - Watch all running jobs"
}

tail_job() {
    job_id="$1"

    # Find matching job directory
    for dir in $JOBS_DIR/aidev-job-*$job_id*; do
        if [ -d "$dir" ]; then
            log_file="$dir/session.log"
            if [ -f "$log_file" ]; then
                echo "=== Tailing $log_file ==="
                echo "=== Press Ctrl+C to stop ==="
                echo ""
                tail -f "$log_file"
                return 0
            fi
        fi
    done

    echo "Job not found: $job_id"
    echo "Run '$(basename $0) list' to see available jobs"
    return 1
}

show_info() {
    job_id="$1"

    # Find matching job directory
    for dir in $JOBS_DIR/aidev-job-*$job_id*; do
        if [ -d "$dir" ]; then
            echo "=== Job Directory: $dir ==="
            echo ""

            info_file="$dir/session-info.json"
            if [ -f "$info_file" ]; then
                echo "--- Session Info ---"
                cat "$info_file"
                echo ""
            fi

            prompt_file="$dir/prompt.txt"
            if [ -f "$prompt_file" ]; then
                echo "--- Prompt (first 50 lines) ---"
                head -50 "$prompt_file"
                echo ""
            fi

            log_file="$dir/session.log"
            if [ -f "$log_file" ]; then
                echo "--- Log (last 30 lines) ---"
                tail -30 "$log_file"
            fi

            return 0
        fi
    done

    echo "Job not found: $job_id"
    return 1
}

watch_running() {
    echo "=== Watching Running AI Dev Jobs ==="
    echo "=== Press Ctrl+C to stop ==="
    echo ""

    running_logs=""
    for dir in $JOBS_DIR/aidev-job-*; do
        if [ -d "$dir" ]; then
            info_file="$dir/session-info.json"
            if [ -f "$info_file" ]; then
                status=$(grep -o '"status"[[:space:]]*:[[:space:]]*"[^"]*"' "$info_file" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')
                if [ "$status" = "running" ]; then
                    log_file="$dir/session.log"
                    if [ -f "$log_file" ]; then
                        running_logs="$running_logs $log_file"
                    fi
                fi
            fi
        fi
    done

    if [ -z "$running_logs" ]; then
        echo "No running jobs found."
        return 1
    fi

    echo "Tailing: $running_logs"
    echo ""
    tail -f $running_logs
}

# Main
case "${1:-list}" in
    list)
        list_jobs
        ;;
    tail)
        if [ -z "$2" ]; then
            echo "Usage: $(basename $0) tail <job_id>"
            exit 1
        fi
        tail_job "$2"
        ;;
    info)
        if [ -z "$2" ]; then
            echo "Usage: $(basename $0) info <job_id>"
            exit 1
        fi
        show_info "$2"
        ;;
    watch)
        watch_running
        ;;
    *)
        echo "Usage: $(basename $0) {list|tail|info|watch}"
        echo ""
        echo "Commands:"
        echo "  list        - List all AI dev jobs"
        echo "  tail <id>   - Follow log for a specific job"
        echo "  info <id>   - Show detailed info for a job"
        echo "  watch       - Tail all running job logs"
        exit 1
        ;;
esac
