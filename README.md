# Subdivision Name Checker (PHP + SQLite)

A simple PHP app for county staff to:
- search existing subdivision names
- check whether a proposed name is available
- reserve an available name
- export the full list to CSV

## Files included
- `index.php` — main application
- `subdivision_names.csv` — starter import file
- `subdivision_names.sqlite` — created automatically on first run

## Server requirements
- PHP 8.0+ recommended
- PDO SQLite extension enabled
- write permission in the app folder so PHP can create `subdivision_names.sqlite`

## Quick local run
### Using PHP built-in server
From this folder:

```bash
php -S localhost:8000
```

Then open:

```text
http://localhost:8000/index.php
```

## First run behavior
On first run the app will:
1. create `subdivision_names.sqlite`
2. create the `subdivision_names` table
3. import `subdivision_names.csv` if the table is empty

## Internal county server deployment
1. Create a folder on the PHP web server.
2. Copy in:
   - `index.php`
   - `subdivision_names.csv`
3. Make sure the web server user can write to that folder.
4. Open the site in a browser.

Example URL:

```text
http://yourserver/subdivision-name-checker/index.php
```

## GitHub setup
### 1. Create a repo
Suggested repo name:

```text
subdivision-name-checker-php
```

### 2. Put these files in the repo
- `index.php`
- `subdivision_names.csv`
- `README.md`
- `.gitignore`

### 3. Recommended `.gitignore`
The SQLite database should usually stay out of Git:

```gitignore
subdivision_names.sqlite
*.db
.DS_Store
```

### 4. Push to GitHub
If starting from this folder:

```bash
git init
git add .
git commit -m "Initial county subdivision name checker"
git branch -M main
git remote add origin https://github.com/YOUR-ORG/subdivision-name-checker-php.git
git push -u origin main
```

## Running from GitHub
GitHub itself does not run PHP apps. You have two common options:

### Option A — store code in GitHub, deploy to county server
This is the best fit for county use.
- keep the source code in GitHub
- pull or download it onto the county PHP server
- run it from Apache/IIS/nginx with PHP enabled

### Option B — use GitHub Codespaces or local clone for testing
Clone the repo and run:

```bash
php -S localhost:8000
```

Then open:

```text
http://localhost:8000/index.php
```

## Important note about shared use
This package uses SQLite, which is great for a small office tool. If county IT later wants a central database server, this can be upgraded to MySQL with minimal changes.

## Recommended next improvements
- admin login
- approve / deny workflow
- reservation expiration date
- notes field
- MySQL version for larger multi-user use
