const express = require('express');
const fs = require('fs');
const cors = require('cors');
const app = express();
const PORT = 3000;

app.use(cors());
app.use(express.json());

const DATA_FILE = 'comments.json';

// Load data
function loadComments() {
    if (!fs.existsSync(DATA_FILE)) return [];
    const data = fs.readFileSync(DATA_FILE);
    return JSON.parse(data);
}

// Save data
function saveComments(comments) {
    fs.writeFileSync(DATA_FILE, JSON.stringify(comments, null, 2));
}

// Get all comments
app.get('/comments', (req, res) => {
    const comments = loadComments();
    res.json(comments);
});

// Add new comment
app.post('/comments', (req, res) => {
    const comments = loadComments();
    const { name, email, content, parentId = null } = req.body;

    const newComment = {
        id: Date.now(),
        name,
        email,
        content,
        parentId,
        timestamp: new Date().toLocaleString()
    };

    comments.push(newComment);
    saveComments(comments);

    res.json({ success: true, message: 'Comment added!', comment: newComment });
});

app.listen(PORT, () => console.log(`Server running on http://localhost:${PORT}`));
