
name: SimpleAuth
main: SimpleAuth\SimpleAuth
version: 2.0.0-legacy
api: 3.0.0
author: LegacyAuth
load: STARTUP
description: SimpleAuth-compatible authentication backend for PocketMine-MP 1.12
commands:
  login:
    description: Log into your account
    usage: /login <password>
  register:
    description: Register an account
    usage: /register <password>
  unregister:
    description: Remove your account
    usage: /unregister <password>
permissions:
  simpleauth.command.login:
    default: true
  simpleauth.command.register:
    default: true
  simpleauth.command.unregister:
    default: true
