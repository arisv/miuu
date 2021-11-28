### Vagrant
For performance reasons if ran inside a VM, a special environment variable `SF_VAGRANT_MODE 1` should be set (in terminal, web server config, .env file etc) to hint Symfony to override cache and log directories. Expect dramatic drops in i/o performance otherwise.

### VSCode XDebug config
```
 "configurations": [
        {
            "name": "Listen for XDebug",
            "type": "php",
            "request": "launch",
            "port": 9003,
            "log": false,
            "xdebugSettings": {
                "max_children": 64,
                "max_depth": 3,
                "max_data": 8192
            },
            "pathMappings": {
                "/app": "${workspaceFolder}"
            }
        }
    ]
```