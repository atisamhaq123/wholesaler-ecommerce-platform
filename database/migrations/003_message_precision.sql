ALTER TABLE messages MODIFY created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6);
ALTER TABLE conversation_participants MODIFY last_read_at DATETIME(6) NULL;
