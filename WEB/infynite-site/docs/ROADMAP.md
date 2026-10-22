# Roadmap and owner decisions (from the feature checklist, 2026-10-22)

Source: the owner's ticks and comments on the "Print shop feature checklist". Items not listed were not ticked.

## Decisions
- No customer portal; customers are records entered by staff (done).
- Not required: bulk customer import, per-spool tracking, warranty tracking (rework and returns only), VPN. Bulk inventory import: undecided (owner asked what it is for).
- Linger (start at boot) is low priority, "later".
- Passwords/e-mail: high priority; real e-mail sending and staff self-service are wanted.

## Requirements captured from comments
1. **Admin governance:** every admin can create and change their own admin key; when an admin changes a key the other admins get an e-mail (transparency); no admin can remove another admin alone, removal needs the agreement of the admins.
2. **Notifications:** the admin configures which notifications go to which people (new order, assignment, low stock, overdue task, overdue invoice, backup result).
3. **Integrations page in Admin settings (test button on each):**
   - rclone + Google Drive: configure the remote and target folder, test connection.
   - FTP/SFTP: host, user, folder, test connection.
   - Choose what is backed up and where: database, uploaded model files, knowledge-base PDFs, application source; per destination on/off and schedule.
   - Uploaded model files stay on the server and can also be pushed to the configured Drive/FTP.
4. **Knowledge base:** export articles as PDF; back them up to Drive/FTP; enable/disable in Admin settings.
5. **Search:** every user can search items, but only results within their role's permissions.
6. **Customers:** opening a customer shows all orders from that customer (exists; verify the view is enough).
7. **Notes:** Google-Keep-like notes (free paragraphs and checklist entries), separate from tasks and procedures.
8. **Recurring tasks:** configurable (daily/weekly/monthly) from the task form.
9. **QR codes** for orders/jobs for tracking.
10. **Purchase orders:** downloadable (PDF), also e-mailable.
11. **Stock update after a purchase:** works today by marking a purchase order received, or a "purchase" stock movement on the item; make this more visible in the UI.

## Status
- **Phases B-F built and tested (2026-10-22):** see `docs/FEATURES.md` for what exists and where. Remaining: server steps, items marked not built there.
- **Phase A done (2026-10-22):** SMTP settings with test button, notification rules and daily summaries, per-admin keys with e-mail notices, mutual-agreement admin removal, staff self-service (profile, password), two-factor login. Still open in A: nothing.

## Proposed build order
- **A. Foundation:** SMTP settings + test e-mail, notification rules, admin governance, staff self-service password/profile, two-factor login.
- **B. Backups and storage:** Integrations page (Drive via rclone, FTP/SFTP, selections, tests), KB PDF export.
- **C. Orders and production:** price calculator, quotation PDF, global search with permission filtering, QR codes, quality checklist, printers and print queue, job costing and automatic filament deduction, delivery challan, rework/returns, 3D preview (STL, hosted locally).
- **D. Inventory and purchasing:** low-stock alerts, reorder suggestions, PO PDF and e-mail, supplier bills.
- **E. Billing:** credit notes, partial payments, invoice e-mail and reminders, GST return summary, expenses and profit and loss, pro-forma invoices, bank statement matching.
- **F. Tasks and notes:** recurring tasks, attachments, calendar, reminders, Keep-style notes, sales/production dashboard, data export.
- **Server (any time):** firewall, HTTPS, health page, second off-machine backup copy, start at boot.
