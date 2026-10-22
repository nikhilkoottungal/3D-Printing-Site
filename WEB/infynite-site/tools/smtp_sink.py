#!/usr/bin/env python3
"""Tiny SMTP server for tests: accepts AUTH LOGIN (user 'u', password 'p') and appends each message as a JSON line to a file.
   usage: smtp_sink.py PORT OUTFILE"""
import socketserver, sys, json, base64, quopri
port, out = int(sys.argv[1]), sys.argv[2]
class H(socketserver.StreamRequestHandler):
    def send(self, s): self.wfile.write((s + "\r\n").encode()); self.wfile.flush()
    def handle(self):
        self.send("220 sink ready"); frm = None; rcpt = []; authed = False
        while True:
            line = self.rfile.readline()
            if not line: return
            c = line.decode(errors="replace").strip(); u = c.upper()
            if u.startswith("EHLO") or u.startswith("HELO"): self.send("250-sink"); self.send("250 AUTH LOGIN")
            elif u == "AUTH LOGIN":
                self.send("334 VXNlcm5hbWU6"); user = base64.b64decode(self.rfile.readline().strip()).decode()
                self.send("334 UGFzc3dvcmQ6"); pw = base64.b64decode(self.rfile.readline().strip()).decode()
                if (user, pw) == ("u", "p"): authed = True; self.send("235 ok")
                else: self.send("535 bad credentials")
            elif u.startswith("MAIL FROM"):
                if not authed: self.send("530 auth required"); continue
                frm = c[10:].strip("<> "); self.send("250 ok")
            elif u.startswith("RCPT TO"): rcpt.append(c[8:].strip("<> ")); self.send("250 ok")
            elif u == "DATA":
                self.send("354 go"); lines = []
                while True:
                    l = self.rfile.readline().decode(errors="replace")
                    if l.strip() == ".": break
                    lines.append(l)
                import email, email.header, email.policy
                msg = email.message_from_string("".join(lines), policy=email.policy.default)
                text = ""; atts = []
                for part in msg.walk():
                    if part.get_content_maintype() == "multipart": continue
                    if part.get_filename(): atts.append({"name": part.get_filename(), "type": part.get_content_type(), "size": len(part.get_payload(decode=True) or b""), "head": (part.get_payload(decode=True) or b"")[:8].decode("latin-1")})
                    elif part.get_content_type() == "text/plain" and not text: text = part.get_content()
                subj = str(msg["subject"] or "")
                with open(out, "a") as f: f.write(json.dumps({"from": frm, "to": rcpt, "subject": subj, "body": text, "attachments": atts}) + "\n")
                rcpt = []; self.send("250 queued")
            elif u == "QUIT": self.send("221 bye"); return
            else: self.send("250 ok")
class S(socketserver.ThreadingTCPServer): allow_reuse_address = True; daemon_threads = True
S(("127.0.0.1", port), H).serve_forever()
