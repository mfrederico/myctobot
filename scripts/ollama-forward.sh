#!/bin/bash
# Forward local port 11435 to remote Ollama server on port 11434 via SSH tunnel.
# Override with env vars: OLLAMA_REMOTE_USER=user OLLAMA_REMOTE_HOST=host.example.com
REMOTE_USER="${OLLAMA_REMOTE_USER:-user}"
REMOTE_HOST="${OLLAMA_REMOTE_HOST:-ollama.example.com}"

tmux new -d -s OLLAMA-FORWARD "/usr/bin/ssh -v -nNT -o StrictHostKeyChecking=no -o ExitOnForwardFailure=yes -o ServerAliveInterval=60 -o ServerAliveCountMax=1 -L11435:127.0.0.1:11434 ${REMOTE_USER}@${REMOTE_HOST}"
