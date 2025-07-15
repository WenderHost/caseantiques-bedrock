# Case Antiques Website

This is the Case Antiques website built upon the Bedrock framework.

## 🧪 Local Development Setup (Symlinked Packages with Composer)

This project uses a flexible development setup that allows you to symlink local versions of Composer packages for development while still pulling from remote repositories in production.

This is accomplished using [`wikimedia/composer-merge-plugin`](https://github.com/wikimedia/composer-merge-plugin) and a local configuration file at `.localdev/composer.local.json`.

---

### 📁 1. Create the `.localdev/` Directory

In the root of your project, create a `.localdev/` directory:

```bash
mkdir .localdev
cd .localdev
```

Then clone the relevant repositories into that folder. For example:

```bash
git clone git@github.com:mwender/auctions-and-items.git
git clone git@github.com:wenderhost/case-antiques-extras.git
git clone git@github.com:wenderhost/centric-pro-caseantiques.git
```

> **Note:** You can use SSH or HTTPS clone URLs depending on your auth setup.

---

### ⚙️ 2. Configure `.localdev/composer.local.json`

Inside `.localdev/`, create a file called `composer.local.json` with the following contents:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "auctions-and-items",
      "options": {
        "symlink": true
      },
      "canonical": false      
    },
    {
      "type": "path",
      "url": "case-antiques-extras",
      "options": {
        "symlink": true
      },
      "canonical": false      
    },
    {
      "type": "path",
      "url": "centric-pro-caseantiques",
      "options": {
        "symlink": true
      },
      "canonical": false      
    }    
  ]
}
```

> This tells Composer to use local symlinked versions of these packages instead of pulling them from GitHub or SatisPress during development.

---

### 🧩 3. Enable Local Dev Mode

In your `composer.json`, the `merge-plugin` section looks like this when **enabled**:

```json
"extra": {
  "merge-plugin": {
    "include": [
      ".localdev/composer.local.json"
    ],
    "recurse": false,
    "replace": false,
    "merge-dev": true
  }
}
```

👆 After you've **enabled** Local Dev Mode, run `composer update`.

When you're ready to deploy or want to test without symlinks, you can **disable** it by renaming the key:

```json
"extra": {
  "merge-plugin-disabled": {
    "include": [
      ".localdev/composer.local.json"
    ],
    "recurse": false,
    "replace": false,
    "merge-dev": true
  }
}
```

> This toggle lets you switch between local and production-style installs without changing the rest of your `composer.json`.

---

### 🌀 4. Install or Update Packages

To install packages and respect the local symlinks:

```bash
composer update mwender/auctions-and-items
```

To switch back to production:

```bash
# Disable the merge plugin as shown above
composer update mwender/auctions-and-items
```
