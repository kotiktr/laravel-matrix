Matrix bot systemd deployment

Files added:
- run_matrix_bot.sh  -> wrapper script that loads .env and runs the PHP bot
- matrix-bot.service -> systemd unit file

Install steps (on the server):

1) Copy service file to systemd directory and make wrapper executable:

   sudo cp deploy/systemd/matrix-bot.service /etc/systemd/system/matrix-bot.service
   sudo cp deploy/systemd/run_matrix_bot.sh /usr/local/bin/run_matrix_bot.sh
   sudo chmod +x /usr/local/bin/run_matrix_bot.sh

2) Reload systemd and start service:

   sudo systemctl daemon-reload
   sudo systemctl enable --now matrix-bot.service

3) Check logs:

   sudo journalctl -u matrix-bot.service -f

Notes:
- Service runs as www-data by default. If your project files are owned by a different user, update the User/Group in the unit file.
- The wrapper sources the project's `.env` file. Ensure secrets are properly secured; for production consider using a secret manager instead of storing credentials in .env.
