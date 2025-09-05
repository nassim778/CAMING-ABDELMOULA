# Network Setup Guide for ABDELMOULA CAMP Application

This guide will help you set up your application to work on another PC connected to the same WiFi network.

## Prerequisites
- Both PCs connected to the same WiFi network
- WAMP server installed on the main PC
- MySQL database with the application data

## Step 1: Find Your Main PC's IP Address

1. On your main PC (where WAMP is running), open Command Prompt or PowerShell
2. Run: `ipconfig`
3. Look for your WiFi adapter and note the IPv4 Address (e.g., 192.168.1.100)

## Step 2: Configure Apache for Network Access

1. Open WAMP Control Panel
2. Click on Apache → httpd.conf
3. Find the line: `Listen 80`
4. Change it to: `Listen 0.0.0.0:80`
5. Find the section with `<Directory "c:/wamp64/www">`
6. Change `Require local` to `Require all granted`
7. Save the file and restart Apache

## Step 3: Configure Windows Firewall

1. Open Windows Defender Firewall
2. Click "Allow an app or feature through Windows Defender Firewall"
3. Click "Change settings" then "Allow another app..."
4. Browse to: `C:\wamp64\bin\apache\apache2.4.x\bin\httpd.exe`
5. Check both "Private" and "Public" boxes
6. Click OK

## Step 4: Update Database Configuration

The database configuration needs to be updated to allow connections from other devices.

## Step 5: Access from Other PC

1. On the other PC, open a web browser
2. Navigate to: `http://[MAIN_PC_IP_ADDRESS]/ZZw`
3. Example: `http://192.168.1.100/ZZw`

## Troubleshooting

### If you can't access the application:
1. Check if both PCs are on the same network
2. Verify the IP address is correct
3. Ensure Windows Firewall is configured properly
4. Check if Apache is running and listening on all interfaces

### If database connection fails:
1. Ensure MySQL is configured to accept remote connections
2. Check MySQL user permissions
3. Verify the database configuration file

## Security Notes

- This setup allows access from any device on your local network
- For production use, consider additional security measures
- Regularly update your WAMP installation
- Use strong passwords for database users
