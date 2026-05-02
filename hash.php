<?php
// استبدلي "password123" بالكلمة اللي تحبي تولدي لها hash
echo password_hash("password123", PASSWORD_DEFAULT);