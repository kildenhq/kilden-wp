# Contributing

Event delivery behavior (batching, retries, payloads, identity signing) is
not decided in this repo: the plugin embeds the
[Kilden PHP SDK](https://github.com/freshworkstudio/kilden-sdk-php), whose
behavior is governed by
[kilden-sdk-spec](https://github.com/freshworkstudio/kilden-sdk-spec). A PR
here that changes delivery behavior without a spec change is rejected;
WordPress-specific behavior (hooks, settings, WooCommerce mapping, consent)
is decided here.

## Development

```sh
composer install
composer test             # phpunit (WP function stubs, no WP install needed)
composer stan             # phpstan level 6
php bin/build-vendor.php  # refresh the vendored core from ../kilden-sdk-php
```

The vendored core under `includes/vendor-kilden/` is generated — never edit
it by hand; change the SDK (spec first) and re-vendor.

Integration tests run against the spec repo's mock capture server:

```sh
(cd ../kilden-sdk-spec/mockserver && go run . -addr :8096) &
KILDEN_MOCK_URL=http://127.0.0.1:8096 composer test
```

## Questions

[Discussions](https://github.com/freshworkstudio/kilden-wp/discussions) —
answers there stay searchable.
