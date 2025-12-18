# AWS Commands Guidelines

## Overview

The AWS commands are used to interact with AWS services. They are used to list AWS services, create AWS services, etc.

## Rules

- the commands should be named like `aws:*:*`
- the commands should be placed in the `App\Console\Commands` namespace
- the commands should be documented in the `app/Console/Commands` namespace
- the commands should extend the `BaseAwsCommand` class
