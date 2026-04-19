OLLAMA_MODEL=kimi-k2.5:cloud
tmux new -d -s assistant-myctobot "/usr/bin/php /var/www/html/default/myctobot/scripts/assist-websocket-server.php"
tmux split-window -h -t assistant-myctobot:0 "ollama serve"
