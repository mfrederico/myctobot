
# Download node version manager
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash

# Set the envfolders as this user
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"  # This loads nvm
[ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"  # This loads nvm bash_completion

# Install node 24
nvm install 24

# Run once - run this is root (first time) all proceeding env's should have this
sudo --preserve-env npx --yes playwright install-deps # May need to do this as root

# Sets up playwright
npx --yes playwright install

# Sets up playwright MCP from cloudflare
npm i -D @cloudflare/playwright-mcp

# Install claude on customers workstation
curl -fsSL https://claude.ai/install.sh | bash
