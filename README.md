# xExtension-Hister

A FreshRSS extension that provides seamless integration with [Hister](https://github.com/Hister-app/hister), allowing you to save articles directly from your RSS feeds to your Hister instance.

## Features (v2.5.0)

*   **1-Click Async Saving**: Send articles to Hister instantly with a button integrated into the FreshRSS interface. The UI updates asynchronously (no page reload) with success/failure animations.
*   **Offline Queueing (Anti-OOM)**: If your Hister instance is unreachable, articles are safely queued to an isolated JSONL file and retried automatically in the background. The queue processing uses a strict line-by-line streaming method to prevent PHP memory exhaustion.
*   **FlareSolverr Integration**: Optionally route links through FlareSolverr before sending them to Hister to bypass Cloudflare anti-bot protections.
*   **Tag & Label Synchronization**: Automatically maps FreshRSS categories, feed names, entry tags, and user labels into Hister's `label` field.
*   **Link De-tracking**: Automatically strips known tracking parameters (`utm_`, `fbclid`, `gclid`, etc.) and unpacks Google/Facebook redirect links before saving them to Hister.
*   **Bulk Processing**: Push your entire existing FreshRSS history to Hister using the background queue.
*   **Secure API Tester**: Test your Hister and FlareSolverr connections directly from the FreshRSS extension configuration panel.
*   **Strict Security**: Native FreshRSS CSRF validation on all actions. State tracking is safely isolated from the main `config.php` to prevent disk thrashing.

## Installation

1.  Download or clone this repository into your FreshRSS extensions directory:
    ```bash
    cd /opt/freshrss/extensions
    git clone https://github.com/widowild1/xExtension-Hister.git xExtension-Hister
    ```
2.  Set the correct ownership and permissions (adjust `www-data` to your web server user):
    ```bash
    chown -R www-data:www-data xExtension-Hister
    find xExtension-Hister -type d -exec chmod 755 {} +
    find xExtension-Hister -type f -exec chmod 644 {} +
    ```
3.  Go to your FreshRSS interface -> **Configuration** -> **Extensions**.
4.  Enable the **Hister** extension.

## Configuration

Click on the gear icon next to the Hister extension to configure it:

*   **Hister instance URL**: The base URL of your Hister instance (e.g., `http://127.0.0.1:4433`).
*   **API Endpoint Path**: The API path to save entries (default: `/api/add`).
*   **API Token**: Your Bearer token, if your Hister instance is secured.
*   **Test Connection**: Use the buttons at the bottom of the configuration page to verify connectivity to Hister and FlareSolverr.

## Compatibility

Tested and optimized for FreshRSS 1.22+ running in Proxmox LXC containers (PHP 8.1+).
