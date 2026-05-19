<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Deployment Guide for Nextcloud Social

This guide provides instructions for deploying the Nextcloud Social app in a production environment.

## Prerequisites

- Nextcloud server (version 28-30)
- Node.js v20.x or higher
- npm v10.x or higher
- Composer
- Database (MySQL, PostgreSQL, or SQLite)

## Installation Steps

### 1. Clone or Install the App

Clone the app into the `apps` folder of your Nextcloud installation:

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/nextcloud/social.git
cd social
```

Or download and extract the app from the Nextcloud App Store.

If the App Store release is blocked by a revoked certificate, install the app locally from the source tree or from a locally built archive instead. That local path does not require Nextcloud App Store signing.

### 2. Install PHP Dependencies

Install the PHP dependencies using Composer:

```bash
composer install --no-dev
```

Or for www-data user:

```bash
sudo -u www-data composer install --no-dev
```

### 3. Build JavaScript Assets

**IMPORTANT:** The JavaScript assets must be built before the app can display content.

First, install Node.js dependencies:

```bash
CYPRESS_INSTALL_BINARY=0 npm install
```

Note: We skip Cypress binary installation as it's only needed for testing.

Then build the production assets:

```bash
npm run build
```

This will create the `js/` directory with all compiled JavaScript files required for the app to function.

The resulting local release archive is written to `build/artifacts/social.tar.gz`. You can unpack that into your Nextcloud `apps/` directory and enable it there without using an App Store certificate.

### 4. Enable the App

Enable the app through your Nextcloud interface or using the command line:

```bash
php occ app:enable social
```

### 5. Configure the App

When you first access the app at `/social`, you may need to configure the cloud address:

- **As an admin:** The app will prompt you to set the cloud address if not already configured
- The cloud address is typically your Nextcloud URL (e.g., `https://cloud.example.com`)
- This can also be configured in your Nextcloud `config.php`:
  ```php
  'overwrite.cli.url' => 'https://cloud.example.com',
  ```

## Troubleshooting

### No Content Displayed

If you see no content when accessing `/social`:

1. **Check if JavaScript is built:**
   ```bash
   ls -la js/
   ```
   You should see files like `social-social.js`, `social-dashboard.js`, etc.

2. **Rebuild JavaScript if needed:**
   ```bash
   npm run build
   ```

3. **Check browser console for errors:**
   - Open browser developer tools (F12)
   - Check the Console tab for JavaScript errors
   - Check the Network tab to see if JavaScript files are loading

4. **Check Nextcloud logs:**
   - Location: `data/nextcloud.log` or via Admin Settings > Logging
   - Look for errors from the `social` app
   - The app now includes detailed logging for debugging

### Cloud Address Not Configured

If you see a setup page requesting cloud address configuration:

1. **For admins:** Enter your Nextcloud URL in the setup form
2. **Via command line:**
   ```bash
   php occ config:system:set overwrite.cli.url --value="https://cloud.example.com"
   ```

### Database Issues

For emoji support, ensure your database is configured for 4-byte UTF-8:

- MySQL/MariaDB: Follow [this guide](https://docs.nextcloud.com/server/stable/admin_manual/configuration_database/mysql_4byte_support.html)

### Profile Page Errors

If you encounter errors like "Cannot read properties of undefined (reading 'acct')" on profile pages:

1. **Clear browser cache and reload:**
   - The issue may be caused by cached JavaScript files
   - Press Ctrl+Shift+R (or Cmd+Shift+R on Mac) to hard reload

2. **Ensure JavaScript assets are rebuilt:**
   ```bash
   npm run build
   ```

3. **Check for proper authentication:**
   - Some profile features require authentication
   - Ensure you're logged in to view authenticated content

### WebSocket Connection Issues

WebSocket connection failures (503 errors) are typically related to server configuration:

1. **Check Nextcloud push server status:**
   - WebSocket support requires proper server configuration
   - Check Apache/Nginx configuration for WebSocket proxy support

2. **For Apache, add WebSocket support:**
   ```apache
   ProxyPass /push/ws ws://localhost:7867/ws
   ProxyPassReverse /push/ws ws://localhost:7867/ws
   ```

3. **For Nginx, add WebSocket support:**
   ```nginx
   location /push/ws {
       proxy_pass http://localhost:7867/ws;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "Upgrade";
   }
   ```

4. **Enable Nextcloud notify_push app:**
   ```bash
   php occ app:enable notify_push
   ```

Note: WebSocket failures won't prevent the app from functioning, but will disable real-time updates.

## Logging

The app includes comprehensive logging for debugging:

- **NavigationController:** Logs page navigation, cloud address setup, and actor creation
- **LocalController:** Logs post creation, timeline retrieval, and stream operations
- **ApiController:** Logs API requests, timeline queries, and media uploads

Check your Nextcloud logs at the following log level for detailed information:
- **Info level:** General operation flow
- **Debug level:** Detailed request/response data
- **Error level:** Errors with stack traces

## Performance Tips

1. **Enable caching:** Use Redis or Memcached for better performance
2. **Background jobs:** Configure cron for background job processing
3. **Database indexing:** Ensure database indices are properly created (automatic on install)

## Updating

When updating the app:

1. Pull latest code or download new version
2. Run `composer install --no-dev`
3. Run `npm run build` to rebuild JavaScript
4. Clear Nextcloud cache: `php occ maintenance:mode --on && php occ maintenance:mode --off`

## Support

For issues and questions:
- GitHub Issues: https://github.com/nextcloud/social/issues
- Nextcloud Community: https://help.nextcloud.com/

## Reset App Data

To reset all Social app data (e.g., to change domain):

```bash
php occ social:reset
```

**Warning:** This will delete all Social app data including posts, follows, and actor information.
