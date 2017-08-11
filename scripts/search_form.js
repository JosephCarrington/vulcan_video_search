// Attempt to enforce that a user must enter at least a title query OR a category query
var vvForm = document.querySelector('#vulcan_video_search');
if(vvForm != null) {
  vvForm.addEventListener('submit', function(e) {
    var titleSearch = vvForm.querySelector('#title');
    var categorySearch = vvForm.querySelector('#category');
    if((!titleSearch.value || titleSearch.value.trim() == '') && !categorySearch.value) {
      tellUserAboutFormRequirements();
      e.preventDefault();
    }
  });
}

function tellUserAboutFormRequirements() {
  vvForm.querySelector("#form_feedback").textContent = "Please enter at least a title or a category to search for.";
}
