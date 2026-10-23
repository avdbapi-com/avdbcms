# 🎬 AVDBCMS Community Edition
### Modern, High-Performance Video Streaming Platform for Everyone
**Zero-Headache Installation • Instant Browser Setup • Universal Hosting Support**

---

## 🌟 Overview

Welcome to **AVDBCMS Community Edition**! AVDBCMS is an enterprise-grade, turn-key video streaming platform built on Laravel and modern HTML5. It comes pre-packaged with everything you need to run a high-traffic video sharing, clipping, and curation site.

Whether you are a **complete beginner** on shared hosting or a sysadmin managing high-performance VPS clusters, AVDBCMS is engineered to install in under **3 minutes** with zero configuration required.

### ✨ Key Features at a Glance
- ⚡ **Universal 1-Click Web Installer**: Fully visual, step-by-step setup wizard in your browser (`/install`).
- 🎥 **Modern Video Player**: Supports HLS (.m3u8), MP4, multiple resolutions, theater mode, autoplay, and keyboard shortcuts.
- 🎲 **Instant Random Stream (`/random`)**: One-click random video playback for seamless content discovery.
- 🎨 **Dynamic Theme Universe**: Switch instantly between Modern Dark, Glassmorphism, Retro, Cinema, and Light modes.
- 📸 **Smart Client-Side Thumbnail Capture**: Auto-generates high-definition video thumbnails directly in the browser during upload, completely bypassing server CPU spikes and Cloudflare bot blocks.
- 🔄 **Automated Video Ingestion**: Built-in AVDB and XVIDAPI pipelines for hands-free video catalog imports.
- 🛡️ **Built-in Security & Math Captcha**: Spam-proof forms with zero third-party API dependencies required.
- 📱 **Mobile & Tablet Optimized**: 100% responsive fluid layout built for smartphones, tablets, and 4K desktops.
- 🌐 **SEO & REST API Ready**: Automatic XML sitemaps, structured schema microdata, and high-speed JSON APIs.

---

## 🚀 3-Minute Quick Start Guide (For Beginners)

You do **NOT** need to be a developer to install AVDBCMS. Just follow these 3 simple steps:

```
┌─────────────────────────────────┐      ┌─────────────────────────────────┐      ┌─────────────────────────────────┐
│     STEP 1: CREATE DATABASE     │ ───> │     STEP 2: UPLOAD & UNZIP      │ ───> │      STEP 3: RUN INSTALLER      │
│  Create MySQL DB in your panel  │      │  Drop zip into public_html/web  │      │  Open yourdomain.com/install    │
└─────────────────────────────────┘      └─────────────────────────────────┘      └─────────────────────────────────┘
```

### Step 1: Create a Database
In your hosting control panel (cPanel, aaPanel, CloudPanel, HestiaCP, CyberPanel, etc.):
1. Create a new **MySQL** or **MariaDB** database (e.g. `my_videos`).
2. Create a database user and password (e.g. `db_user` / `SecretPassword123!`).
3. Assign the user to the database with **ALL PRIVILEGES**.

### Step 2: Upload and Unzip
1. Download the full package `avdbcms-1.2.0.zip` from [Download AVDBCMS](https://help.avdbapi.com/documentation/download-avdbcms/) (the `avdbcms-update-1.2.0.zip` is only for updates).
2. Upload it into your website root directory (`public_html` or web folder).
3. Extract (unzip) the zip file directly into the folder.

### Step 3: Run the Web Installer
1. Open your web browser and navigate to:
   ```
   https://yourdomain.com/install
   ```
   *(Or `https://yourdomain.com/index.php/install` if your host does not have URL rewrite enabled).*
2. The installation wizard will guide you through:
   - **System Check**: Automatically checks your PHP version and extensions (all green checkmarks).
   - **Filesystem Permissions**: Automatically verifies writable directories.
   - **Database Details**: Enter your Database Host (`127.0.0.1` or `localhost`), Database Name, Username, and Password. Under **Import Mode**, keep **Import SQL backup** (`example.sql`): it fills the database with the starting settings, languages, categories and plans. The new site has no videos, channels, backups or import jobs; the database adds two sample members that are not admins (`pbergstrom`, `blackbox_creator`), which you can delete in **Members › All members**.
   - **Admin Account**: Enter your desired administrator email, username, and password.
   - **Finish**: Click **"Install Now"** — the installer sets up the selected database, creates your admin account, and locks the installer safely. AVDB and XVIDAPI imports stay idle until an admin starts an import or enables a schedule.

🎉 **That's it! Your video streaming site is live!**

Every step in detail: [Install AVDBCMS step by step](https://help.avdbapi.com/documentation/installation-guide-for-avdbcms/).

---

## 📋 System Requirements

AVDBCMS runs on virtually any modern Linux or Windows web server:

| Component | Minimum Requirement | Recommended |
| :--- | :--- | :--- |
| **PHP Version** | PHP 8.3 (64-bit) | **PHP 8.3+** (64-bit) |
| **Database** | MySQL 5.7 or MariaDB 10.3 | **MySQL 8.0+** or **MariaDB 10.6+** |
| **Web Server** | Apache 2.4, Nginx, or OpenLiteSpeed | **Nginx** or **OpenLiteSpeed** |
| **Memory (RAM)** | 1 GB RAM | **2 GB+ RAM** |
| **Disk Storage** | 2 GB free SSD | **20 GB+ SSD / NVMe** |

### Required PHP Extensions
Most hosting panels have these enabled by default (the installer lists each one as Loaded or Missing):
- Required: `ctype`, `curl`, `dom`, `filter`, `hash`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xml`
- Recommended: `bcmath`, `fileinfo`, `gd`, `zip`

---

## 🖥️ Panel-Specific Quick Tips

AVDBCMS works seamlessly across all major hosting panels:

### 🔹 cPanel
- Upload the zip to `public_html/` and click **Extract**.
- Ensure PHP version is set to **PHP 8.3 or newer** in **MultiPHP Manager**.
- Visit `https://yourdomain.com/install`.

### 🔹 aaPanel
- Create a website with PHP 8.3 or newer.
- Upload and unzip into `/www/wwwroot/yourdomain.com/`.
- In **Site Settings -> URL Rewrite**, select `laravel5` (or leave default).
- Set permissions on `storage` and `bootstrap/cache` to `775` (owner `www:www`).

### 🔹 CloudPanel
- Create a **PHP Site** (PHP 8.3 or newer).
- Upload and extract into the site's `htdocs/` folder.
- Ensure the site user owns the files (`chown -R siteuser:siteuser .`).

### 🔹 HestiaCP
- Add your web domain with PHP 8.3 or newer.
- Upload and unzip to `/home/user/web/yourdomain.com/public_html/`.
- Ensure files are owned by your user (`admin:admin`).

### 🔹 CyberPanel (OpenLiteSpeed)
- Create website with PHP 8.3 or newer.
- Upload and extract into `/home/yourdomain.com/public_html/`.
- If using OpenLiteSpeed rewrite rules, restart LiteSpeed after install:
  ```bash
  systemctl restart lsws
  ```

### 🔹 1Panel / Docker
- Create a PHP environment container (PHP 8.3 or newer) linked to MySQL.
- Extract files into the website root directory.
- Database host will typically be your MySQL container name (e.g. `1Panel-mysql`).

---

## 🔧 Post-Installation Quick Checklist

Once your site is running:

1. **Log in to Admin**:
   - Visit `https://yourdomain.com/login` and enter the administrator credentials you created during install.
   - Access the dashboard at `https://yourdomain.com/admin`.

2. **Storage Link (For Video/Image Uploads)**:
   - If uploaded videos or avatars don't display, run this one-line command via SSH or panel terminal:
     ```bash
     php artisan storage:link
     ```

3. **Background Tasks & Scheduler (Optional, Recommended)**:
   - To enable automated video imports and daily metric calculations, add this single Cron Job in your panel:
     ```bash
     * * * * * cd /path-to-your-site && php artisan schedule:run >> /dev/null 2>&1
     ```

---

## ❓ Frequently Asked Questions (Troubleshooting)

### Q: I get a 500 Server Error after uploading. What should I check?
**A:** Check folder permissions! The `storage/` and `bootstrap/cache/` directories must be writable by your web server:
```bash
chmod -R 775 storage bootstrap/cache
```

### Q: Why does `/index.php` appear in my URLs?
**A:** AVDBCMS includes an automatic **Zero-Config Bridge** so your site works even if your host doesn't support Apache `.htaccess` or Nginx rewrites. To use clean URLs (without `index.php`), simply ensure `.htaccess` is enabled on Apache/LiteSpeed, or add standard Laravel rewrites on Nginx:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```
If the web root is the project folder itself (shared hosting), also include [docs/server-configs/nginx-project-root.conf](docs/server-configs/nginx-project-root.conf): it serves uploads (`/storage/...`) and published assets (`/vendor/...`) and blocks the application source. Panel-by-panel file locations are in [docs/server-configs/README.md](docs/server-configs/README.md). Apache and OpenLiteSpeed need nothing: the shipped `.htaccess` does the same job.

### Q: Can I change my site name, logo, or theme later?
**A:** Absolutely! Go to **Admin -> Settings › Site basics** to change the site name, contact email and logo, and to **Admin -> Appearance** to change the theme and colours, anytime with zero code edits.

### Q: How do I update AVDBCMS?
**A:** From 1.2.0 on, **Tools › Updates** in the admin shows when a new release is out and installs it with one click (nothing updates by itself). The manual way, going back, and moving from a community test package are in [UPGRADE.md](UPGRADE.md). What changed: [CHANGELOG.md](CHANGELOG.md) and the [AVDBCMS changelog](https://help.avdbapi.com/documentation/avdbcms-changelog/).

### Q: Where can I find detailed technical instructions?
**A:** For comprehensive documentation covering production queue workers (Supervisor), external CDN video streaming (Upload18/S3/MinIO), and API scrapers, please see the full [COMMUNITY_SETUP_GUIDE.md](COMMUNITY_SETUP_GUIDE.md) bundled with your installation.

---

## 📄 License & Community Support

From release 1.2.0, AVDBCMS is under the **AVDBCMS Community License 1.0** (file [LICENSE](LICENSE)). In plain words: **share it, don't sell it.**
- **Use it for free**, also for your own website that earns money, for example with ads or premium plans.
- **Copy it, change it and share it.** Shared copies stay free and under the same license, keep every notice, mark their changes and carry no money-making code or links of the person who shares them.
- **Never sell it.** No payment for copies, changed versions, themes or installation packages of AVDBCMS, and none for installing, setting up, running, supporting or changing AVDBCMS for other people.
- **The original MIT project:** AVDBCMS is based on an open-source project by Anton Bourtnik under the MIT License (Copyright (c) 2024 Anton Bourtnik). That original code keeps its MIT License; its notice is kept word for word in `LICENSE` (Part B, section B1). Laravel, the other PHP and JavaScript packages and the FFmpeg programs in `bin/` keep their own licenses: see [NOTICE](NOTICE).

The license text in `LICENSE` is the one that counts; this summary is not legal advice.

- **Install guide**: [Install AVDBCMS step by step](https://help.avdbapi.com/documentation/installation-guide-for-avdbcms/)
- **Download**: [Download AVDBCMS](https://help.avdbapi.com/documentation/download-avdbcms/)
- **Changelog**: [AVDBCMS changelog](https://help.avdbapi.com/documentation/avdbcms-changelog/) and [CHANGELOG.md](CHANGELOG.md); updating: [UPGRADE.md](UPGRADE.md)
- **Documentation**: [COMMUNITY_SETUP_GUIDE.md](COMMUNITY_SETUP_GUIDE.md) and the help center https://help.avdbapi.com/
- **Support**: Telegram group https://t.me/avdb_api; bug reports at https://github.com/avdbapi-com/avdbcms/issues

*Built with ❤️ for creators, publishers, and the open video web.*
