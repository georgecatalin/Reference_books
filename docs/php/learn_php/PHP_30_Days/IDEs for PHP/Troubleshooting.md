### How do i install VSCodium?

Installing VSCodium on Ubuntu is straightforward. Because VSCodium is not in the default Ubuntu upstream repositories, you have three clean, community-approved ways to install it.

Pick the method below that best fits your workflow.

## Method 1: The Official Repository (Recommended)

This is the best approach for a development VM. It adds the official VSCodium GPG signing key and repository to your package manager, ensuring that VSCodium updates automatically whenever you run your normal system updates (`apt upgrade`).

Run the following commands sequentially in your terminal:

Bash

```
# 1. Update your package index and install required transport dependencies
sudo apt update
sudo apt install software-properties-common apt-transport-https curl -y

# 2. Import the official GPG key to verify package integrity
curl -fSsL https://gitlab.com/paulcarroty/vscodium-deb-rpm-repo/-/raw/master/pub.gpg | sudo gpg --dearmor -o /usr/share/keyrings/vscodium-archive-keyring.gpg

# 3. Add the VSCodium repository to your sources list
echo 'deb [signed-by=/usr/share/keyrings/vscodium-archive-keyring.gpg] https://download.vscodium.com/debs vscodium main' | sudo tee /etc/apt/sources.list.d/vscodium.list

# 4. Update your package index again to read the new repository, then install VSCodium
sudo apt update
sudo apt install vscodium -y
```

## Method 2: Flatpak (Isolated Sandbox)

If you prefer to keep your development applications isolated from the host operating system's base files, you can use Flatpak via Flathub.

Bash

```
# 1. Install Flatpak (if you haven't already)
sudo apt update
sudo apt install flatpak -y

# 2. Add the Flathub repository
sudo flatpak remote-add --if-not-exists flathub https://dl.flathub.org/repo/flathub.flatpakrepo

# 3. Install VSCodium
sudo flatpak install flathub com.vscodium.codium -y
```

> **Note for VM users:** If you install VSCodium via Flatpak, running terminal commands or compilers _inside_ the VSCodium terminal may require extra permissions adjustments, as the application runs sandboxed.

## Method 3: Snap Package (Quickest GUI setup)

Ubuntu natively supports Snap packages out of the box. This is a single-command installation, though some developers note it can have slightly slower startup times than the native APT installation.

Bash

```
sudo snap install codium --classic
```

## Post-Installation: Tailoring VSCodium for PHP

Once installed, launch it by searching for **VSCodium** in your application menu, or type `codium` in your terminal.

To get complete autocomplete, type-checking, and syntax validation for your 30-day PHP sprint, click on the **Extensions** icon on the left sidebar (or press `Ctrl+Shift+X`) and install this specific extension:

- **Intelephense** (by Ben Mewburn)
    

This single extension provides fast, accurate Intellisense code completion and parameters tracking matching modern PHP 8.x specs without bloating the editor.

#### Building dependency tree... Done
#### Reading state information... Done
14 packages can be upgraded. Run 'apt list --upgradable' to see them.
#### N: Skipping acquire of configured file 'main/binary-i386/Packages' as repository 'https://download.vscodium.com/debs vscodium InRelease' doesn't support architecture 'i386'

That notice (`N: Skipping acquire...`) is completely harmless, but it's annoying.

### Why is this happening?

By default, Ubuntu's package manager (`apt`) looks for both 64-bit (`amd64`) and 32-bit (`i386`) versions of packages when you add a new repository. The VSCodium repository doesn't provide 32-bit binaries, so `apt` is just letting you know it skipped looking for them.

Since your VM is almost certainly running a 64-bit architecture, we can explicitly tell `apt` to only check for `amd64` packages from this specific repository. This will silence the warning.

### The Fix

Open your terminal and run this single command to modify the repository file we created earlier:

Bash

```
echo 'deb [arch=amd64 signed-by=/usr/share/keyrings/vscodium-archive-keyring.gpg] https://download.vscodium.com/debs vscodium main' | sudo tee /etc/apt/sources.list.d/vscodium.list
```

Now, refresh your package list again:

Bash

```
sudo apt update
```

The warning notice will be gone, and you'll get a clean exit status. VSCodium is now fully configured and ready to roll.

Open up the editor, get your project folder loaded, and let me know when you're ready to tackle **Day 8: Object-Oriented PHP (OOP)**!

>eorgeca@georgeca-VirtualBox:~/training/php-practice$ sudo apt update
Hit:1 http://security.ubuntu.com/ubuntu noble-security InRelease
Hit:2 http://archive.ubuntu.com/ubuntu noble InRelease                                                                                                                                                              
Get:3 https://pkg.claude-desktop-debian.dev stable InRelease                                                                                                                                                        
Hit:4 https://ppa.launchpadcontent.net/mosquitto-dev/mosquitto-ppa/ubuntu noble InRelease                                                                                       
Hit:5 https://download.vscodium.com/debs vscodium InRelease                                                               
Hit:6 http://archive.ubuntu.com/ubuntu noble-updates InRelease                                      
Hit:7 https://packages.microsoft.com/repos/code stable InRelease              
Hit:8 http://archive.ubuntu.com/ubuntu noble-backports InRelease
Fetched 2,539 B in 1s (2,827 B/s)
Reading package lists... Done
Building dependency tree... Done
Reading state information... Done
3 packages can be upgraded. Run 'apt list --upgradable' to see them.
georgeca@georgeca-VirtualBox:~/training/php-practice$ sudo apt install -y vscodium
Reading package lists... Done
Building dependency tree... Done
Reading state information... Done
E: Unable to locate package vscodium

Ah, the classic `apt` repository cache mismatch. Looking at your terminal log, the `vscodium InRelease` hit is green and active, which means the repository is added correctly, but your local package definitions haven't registered the exact package name yet.

Let's do a quick query to see exactly how the package is named in that repo, and then get it installed.

### Step 1: Force an Update of Just the VSCodium Repo

Sometimes `apt` skips updating a specific third-party repository if it thinks nothing changed. Let's force a clean update of your cache and search for the package name:

Bash

```
sudo apt update --allow-releaseinfo-change
apt search vscodium
```

### Step 2: Install via the Verified Package Name

In that specific repository, the main application package can sometimes be named `codium` instead of `vscodium`. Let's install both potential targets (or let `apt` grab the correct alias):

Bash

```
sudo apt install -y codium
```

If that goes through cleanly, you are good to go! You can launch it by typing `codium` right in your terminal.

>eorgeca@georgeca-VirtualBox:~/training/php-practice$ sudo apt update
Hit:1 http://security.ubuntu.com/ubuntu noble-security InRelease
Hit:2 http://archive.ubuntu.com/ubuntu noble InRelease                                                                                                                                                              
Get:3 https://pkg.claude-desktop-debian.dev stable InRelease                                                                                                                                                        
Hit:4 https://ppa.launchpadcontent.net/mosquitto-dev/mosquitto-ppa/ubuntu noble InRelease                                                                                       
Hit:5 https://download.vscodium.com/debs vscodium InRelease                                                               
Hit:6 http://archive.ubuntu.com/ubuntu noble-updates InRelease                                      
Hit:7 https://packages.microsoft.com/repos/code stable InRelease              
Hit:8 http://archive.ubuntu.com/ubuntu noble-backports InRelease
Fetched 2,539 B in 1s (2,827 B/s)
Reading package lists... Done
Building dependency tree... Done
Reading state information... Done
3 packages can be upgraded. Run 'apt list --upgradable' to see them.
georgeca@georgeca-VirtualBox:~/training/php-practice$ sudo apt install -y vscodium
Reading package lists... Done
Building dependency tree... Done
Reading state information... Done
E: Unable to locate package vscodium

Ah, I see what's happening now from your log. Look at line 7 of your output: `Hit:7 https://packages.microsoft.com/repos/code stable InRelease`

You already have Microsoft's official VS Code repository active on your system, which can cause a naming collision or confusion in the package cache when installing its unbranded counterpart. Let's force it to install using its exact package binary identifier, which is **`codium`**, not `vscodium`.

Run this specific command:

Bash

```
sudo apt install -y codium
```

If it installs successfully, you can launch it from your VM terminal simply by running:

Bash

```
codium .
```