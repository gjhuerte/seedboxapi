---
trigger: always_on
---

* Always run `php`, `composer`, and `artisan` commands inside the Docker container named `seedboxapi`.
* Do not run these commands locally.
* Use the following syntax: `docker exec -i seedboxapi <command>`.
