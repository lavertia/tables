# Lavertia Tables

**Experimental v0.2 package, no stable release.**

Lavertia Tables defines PHP-first table descriptors, filters, allowlisted sorting and scoped Eloquent pagination. The standalone PHP implementation is under review in [PR #2](https://github.com/lavertia/tables/pull/2). The `main` branch is a repository scaffold until that PR is verified and merged.

The consuming Laravel application must authorize records and scope builders to the current user or tenant **before** sending data to the table renderer. UI controls are not authorization controls.

See the [Lavertia documentation](https://lavertia.github.io/lavertia-doc/), [release-readiness issue](https://github.com/lavertia/lavertia/issues/3), and [security policy](SECURITY.md).

**License:** MIT; see [LICENSE.md](LICENSE.md). A public source repository is not a production-ready Composer release.
