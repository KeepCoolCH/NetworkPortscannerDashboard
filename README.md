# 🔍 Network Portscanner Dashboard

A lightweight PHP dashboard that lists all **LISTEN** ports on your system, checks their external reachability via your public IP, and shows all active **ESTABLISHED** connections.  
Supports both **macOS** and **Linux**, with a manual OS switch button.

---

## ✨ Features

- 🖥️ Detects your operating system automatically (macOS/Linux)  
- 🔘 Manual switch between macOS and Linux mode  
- 🌍 Retrieves your **public IP address** using multiple fallback services  
- 🔎 Lists all **LISTEN ports**:
  - Shows bound address (loopback vs all interfaces)
  - Extracts port number and protocol
  - Tests **external reachability** (via `fsockopen`)  
  - Annotates results with helpful notes (loopback-only, NAT/hairpin issues, etc.)  
- 📊 Sorted results:  
  1. Reachable ports  
  2. Unknown status  
  3. Not reachable  
- 🌐 Lists **active ESTABLISHED connections** (via `lsof`)  
- 📜 Raw output panel for debugging (shows original netstat/ss output)  
- 🎨 Clean, responsive HTML dashboard with badges (✅ ❌ ⚠️)

---

## 📸 Screenshot

![Screenshot](https://online.kevintobler.ch/projectimages/NetworkPortscannerDashboard.png)

---

## 📦 Requirements

- > PHP **7.4+** (CLI functions enabled: `shell_exec`, `fsockopen`)  
- System commands:
  - macOS: `netstat`, `lsof`  
  - Linux: `ss` (preferred), `netstat`, `lsof`, `dig` (optional for IP)  
- Permissions to run shell commands  

---

## 🚀 Usage

1. Copy the file to your PHP web server
2. Open in your browser:
   ```
   http://localhost/portscanner.php
   ```
3. Use the OS switch buttons (`macOS` / `Linux`) in the header if needed.

---

## ⚠️ Notes

- **Router / NAT / Firewall**:  
  Local tests may show ports as “not reachable” if your router/firewall blocks hairpinning or inbound connections. For full confirmation, test from an external device on another network.  

- **Loopback addresses** (`127.0.0.1`, `::1`, `localhost`):  
  Always marked as local-only, never reachable from outside.  

- **Public IP detection**:  
  Uses `api.ipify.org`, `ifconfig.me`, `checkip.amazonaws.com`, and OpenDNS `dig` as fallbacks.  

---

## 📷 Screenshot

Example output:

```
Proto  Local Address      Port  Bind    Externally Reachable?  Note
tcp    0.0.0.0:80         80    (all)   ✅ Yes                 Reachable (public IP succeeded)
tcp    127.0.0.1:3306     3306  127.0.0.1 ❌ No                 Loopback only (local access)
```

---

## 🔒 Security Warning

- This script executes `netstat`, `ss`, and `lsof` using `shell_exec`.  
- Do **not** expose it to the public internet without restrictions (e.g., password protection, IP allowlist).  
- Intended for **local diagnostic use**.

---

## 📜 License

This project is licensed under the **MIT License** – feel free to use, modify, and distribute.
