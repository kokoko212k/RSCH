// Toggle Dropdown User
const userIcon = document.querySelector(".user-icon");
const userMenu = document.getElementById("userMenu");

if (userIcon) {
    userIcon.addEventListener("click", function (e) {
    e.stopPropagation();
    userMenu.style.display = userMenu.style.display === "block" ? "none" : "block";
    });
}

document.addEventListener("click", function (e) {
    if (userMenu && !userIcon.contains(e.target)) {
    userMenu.style.display = "none";
    }
});