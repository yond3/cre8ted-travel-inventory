-- Allow equipment stock-up purchases (company buy into storage, no department).
ALTER TABLE purchase_requests
    MODIFY reason ENUM('replacement', 'new_need', 'other', 'stock_up') NULL;
