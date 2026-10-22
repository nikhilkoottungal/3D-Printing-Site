# What the internal system does and where (2026-10-22)

Office-LAN, staff-only. Customers are records entered by staff; they never log in. Roles: **Owner** (everything), **Sales**, **Production**, **Purchasing**. The sidebar shows only what a role may open.

## Orders and customers
| Feature | Where | Who |
|---|---|---|
| New order (walk-in, phone, e-mail), STL/3MF files, status flow with history | Orders | Owner, Sales, Production |
| Price calculator (material, hours, post-processing, setup, margin, rush, minimum); "use as quote" | Price calculator, or from an order | all staff |
| Quotation PDF, delivery challan PDF, job label with QR code | order page, "Documents" | all staff |
| 3D preview of STL files (in the browser, no data leaves the server) | order page, file list | all staff |
| Quality check list saved against the order (who ticked, when) | order page | Owner, Sales, Production |
| Rework and returns (open/resolved, cost) | order page | Owner, Sales, Production |
| Delivery details: promised date, courier, tracking | order page | Owner, Sales, Production |
| Customers with address, GSTIN, state, order and invoice history | Customers | view: all; edit: Owner, Sales |
| Search across orders, customers, invoices, tasks, procedures, stock, suppliers | sidebar search | results limited by role |

## Production
Printers (status, next maintenance) and Print queue (14-day calendar). Plan a print job from an order; finishing a job deducts the filament from stock, books material + machine cost and shows margin on the order. Owner and Production manage; everyone can look.

## Inventory and purchasing
Inventory (reorder levels, stock moves, grams per unit for filament). **Suppliers & POs**: suppliers, purchase orders (PDF download, e-mail to supplier, "mark received" adds stock), **Reorder suggestions** (creates a PO), **Supplier bills** with part payments, **Expenses**. Owner and Purchasing.

## Billing and accounts
Invoices with CGST+SGST/IGST, part payments, pro-forma invoices (convert to tax invoice), invoice PDF, e-mail and payment reminders (manual, or automatic daily if switched on), credit notes with refunds, **GST summary** (B2B, B2C, credit notes, HSN, net GST after input credit; CSV), **Profit and loss** (Owner), **Bank statement matching** (CSV upload, suggested payments), Reports with CSV. Owner and Sales (supplier-side items: Owner and Purchasing).
Not included: e-invoicing / e-way bill, GST return filing itself (the summary is for your accountant).

## Tasks, notes, calendar
Tasks (list/board, comments, activity log, file attachments), **Recurring tasks** (daily/weekly/monthly), **Notes** (notes and checklists, private or shared, pin, colour, archive, files), **Calendar** (tasks, orders due, print jobs, payments and deliveries, maintenance), overview with orders by stage and what is due. Knowledge base and procedures with versions, PDF export.

## My account and team
My account: profile picture, personal and work details (address, date of birth, blood group, emergency contact, designation, joining date, previous experience, qualifications), identity (Aadhaar, PAN), bank details for salary, ID proof copies (PDF/JPG/PNG), change password (collapsed section), two-factor login with QR code. Aadhaar, PAN, bank account numbers and ID copies are encrypted at rest; Aadhaar is always shown masked. Only the person and the Owner can see them (Owner views are logged). Owner: **Payroll sheet** CSV in Data export.
**Permanent deletion:** an admin can delete a colleague they have already **removed** (the Delete permanently option appears only on removed accounts, never on active or frozen ones, or on admins; Team & roles > Delete permanently: their own password and the colleague's e-mail typed exactly), and anyone can delete their own account (My account). Admins cannot be deleted by one person (request that every admin agrees to), and cannot delete themselves this way. Deleting erases the person's details, files and private notes; past work stays without their name; admins are told by e-mail. Team & roles (Owner): add people, change roles, **Freeze** (sign-in blocked at once, everything else kept; Unfreeze restores access as it was), **Remove** (signed out, open tasks unassigned, can be restored with a new password link), Reset 2FA. Admins are removed by the request every admin must agree to.

## Admin (Owner, behind the personal admin key)
Admin settings (company, GST, numbering, bank, pricing, quality list, e-mail), notification rules, **Integrations** (Google Drive via rclone, FTP/FTPS/SFTP, what is copied where, tests, run now), personal admin keys, admin removal needs every admin's agreement, Team and roles, Data export (CSV), Backups page. My account (password, two-factor login).

## Alerts by e-mail (rules set by the Owner)
New order, status change, invoice paid, invoices overdue (daily), PO received, low stock, tasks overdue (daily), supplier bills due (daily); security notices to all admins. Daily 08:00 job (`deploy/infynite-daily.timer`) also creates recurring tasks and sends customer reminders.

## Server jobs (systemd user timers)
Nightly encrypted backup 22:30, off-site copies 22:45, daily summaries 08:00.

## Still open (needs you / sudo)
Start at boot (linger), firewall for the office subnet, HTTPS in front, a second off-machine backup copy, a restore drill with real data, VPN (not wanted). Not built: stock count sheet, task time tracking, audit log screen, per-user permissions beyond the four roles, bulk imports, WhatsApp/SMS to customers.
