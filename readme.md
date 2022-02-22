# Source of Truth

Broadcasts message to various environments to modify the content source of truth.

Contributors:

- [@bickle66](https://github.com/bickle66)
- [@mr-moto](https://github.com/mr-moto) / [@pg-moto](https://github.com/pg-moto)

## Description

Source of Truth is a plugin maintained by P&G that allows administrators to create a list of environments (UAT, Dev, production etc) and coordinates who is the current source of truth for maintaining WordPress content.

At a glance, this plugin adds the following:

- Administrators can add / remove environments.
- Administrators can ensure token matches on multiple environments to securely (?) communicate.

In addition, the Source of Truth plugin includes several filters that show different messages on all various environments wether they are or are not the current source of truth. If not, the message links to the current SOT.

## Changelog

### 0.1.1

- fix: a few null value variables were causing errors. added additional null checks to prevent these issues.
- fix: default options is a nested array on plugin activate which caused errors when trying to access them. Changed the default options to be a non nested array.
- chore: readme update.
- chore: update composer package ( composer/installers - 1.8 => 2.0.1 )

### 0.1

- Initial release.

## Frequently Asked Questions

### How is Bryan && Kevin so awesome?

- Not sure!
