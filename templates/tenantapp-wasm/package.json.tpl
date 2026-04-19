{
    "name": "{{APP_SLUG}}",
    "version": "1.0.0",
    "description": "{{APP_NAME}} - WASM tenant app powered by php-cgi-wasm",
    "type": "module",
    "scripts": {
        "start": "node server.mjs"
    },
    "dependencies": {
        "php-cgi-wasm": "0.0.9-alpha-32",
        "php-wasm-sqlite": "0.0.9-alpha-32"
    }
}
