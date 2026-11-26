CREATE TABLE "erp_credential_attempts" ("key" varchar(64) not null, "attempts" integer not null, "expires_at" integer not null, primary key ("key"));
CREATE INDEX "erp_credential_attempts_expires_at_index" ON "erp_credential_attempts" ("expires_at");
CREATE TRIGGER erp_d1_rev_erp_credential_attempts_insert AFTER INSERT ON erp_credential_attempts BEGIN UPDATE erp_d1_revision SET revision=revision+1 WHERE id=1; END;
CREATE TRIGGER erp_d1_rev_erp_credential_attempts_update AFTER UPDATE ON erp_credential_attempts BEGIN UPDATE erp_d1_revision SET revision=revision+1 WHERE id=1; END;
CREATE TRIGGER erp_d1_rev_erp_credential_attempts_delete AFTER DELETE ON erp_credential_attempts BEGIN UPDATE erp_d1_revision SET revision=revision+1 WHERE id=1; END;
INSERT INTO migrations(migration,batch) VALUES('2026_11_26_100000_create_erp_credential_attempts',(SELECT COALESCE(MAX(batch),0)+1 FROM migrations));
