</main>

      <!-- <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>Copyright 2026 adminHMD. <br> Developed by <a target="_blank" class="fw-bold text-success"
              href="https://github.com/HasanMahmudDev">Md. Hasan Mahmud</a> • Distributed by <a target="_blank"
              class="fw-bold text-success" href="https://themewagon.com">ThemeWagon</a> </span>
          <span>Professional dashboard template.</span>
        </div>
      </footer> -->
    </div>
  </div>

<script>
window.addEventListener("pageshow", function (event) {
    // Agar page browser cache (bfcache) se load hua hai toh page ko forcefully reload karein
    if (event.persisted) {
        window.location.reload();
    }
});
</script>
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
 <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
 <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

 <script>
$(document).ready(function() {
    // ID ki jagah hum Class (.custom-datatable) use kar rahe hain
    $('.custom-datatable').DataTable({
        "pageLength": 10,
        "responsive": true,
        "language": {
            "search": "",
            "searchPlaceholder": "Search here...", 
            "lengthMenu": "Show _MENU_ entries",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries",
            "paginate": {
                "previous": "<i class='fa-solid fa-chevron-left'></i>",
                "next": "<i class='fa-solid fa-chevron-right'></i>"
            }
        },
        "order": [[0, "desc"]],
        "columnDefs": [
           
            { "orderable": false, "targets": "no-sort" } 
        ]
    });
});
</script>
</body>

</html>