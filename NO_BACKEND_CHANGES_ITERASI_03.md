# Iterasi 03 - Tidak ada perubahan backend

Patch iterasi 03 fokus pada hardening bottleneck jaringan di flow kasir inti pada sisi frontend Android normal dan Bluetooth:

- customer list local-first dari cache offline
- warm-up cache customer saat bootstrap/login
- customer phone search cache-first + degraded-server fallback
- create customer tetap online-only dengan UX yang jujur
- squad lookup fallback diperkeras untuk kondisi server degraded
- cache squad diperkaya dari hasil pilih user

Backend sengaja tidak diubah pada iterasi ini agar risiko regresi minimum.
