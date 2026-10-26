<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('admin-title')</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @include('back-end.includes.theme-head')

  <!-- Font: Inter (English) + Hind Siliguri (Bangla) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="{{ asset('/') }}back-end/plugins/fontawesome-free/css/all.min.css">
  <!-- Ionicons -->
  <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
  <!-- Tempusdominus Bootstrap 4 -->
  <link rel="stylesheet" href="{{ asset('/') }}back-end/plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
  <!-- iCheck -->
  <link rel="stylesheet" href="{{ asset('/') }}back-end/plugins/icheck-bootstrap/icheck-bootstrap.min.css">
 
  <!-- Theme style -->
  <link rel="stylesheet" href="{{ asset('/') }}back-end/dist/css/adminlte.min.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="{{ asset('/') }}back-end/plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Daterange picker -->
  <link rel="stylesheet" href="{{ asset('/') }}back-end/plugins/daterangepicker/daterangepicker.css">

  <!-- Select2 -->
  <link rel="stylesheet" href="{{ asset('/') }}back-end/plugins/select2/css/select2.min.css">
  <link rel="stylesheet" href="{{ asset('/') }}back-end/plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">

  <link href="{{ asset('/') }}back-end/dist/css/jodit.min.css" rel="stylesheet">
  <link href="{{ asset('/') }}back-end/dist/css/spectrum.css" rel="stylesheet">

<!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/timepicker/1.3.5/jquery.timepicker.min.css"> -->

<!-- Flatpickr CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

  <!-- summernote -->

  <!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">

   <link
      href="{{ asset('/') }}back-end/assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.css"
      rel="stylesheet"
    />

    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">

    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">

     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">

   <!--   <link rel="manifest" href="/manifest.json">

     <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/service-worker.js')
                .then(reg => console.log('Service Worker Registered'))
                .catch(err => console.log('Service Worker Failed', err));
        });
    }
</script> -->

  <!-- Premium theme (adminlte er upor design layer) -->
  <link rel="stylesheet" href="{{ asset('/') }}back-end/css/premium.css?v={{ filemtime(public_path('back-end/css/premium.css')) }}">
  @stack('admin-styles')
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav align-items-center">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button" title="Menu"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="{{ route(auth()->user()->homeRoute()) }}" class="nav-link"><i class="fas fa-home mr-1"></i> Home</a>
      </li>
      <li class="nav-item d-none d-md-inline-block">
        <span class="nav-link topbar-clock dateNow1">
            <i class="far fa-calendar-alt"></i>
            <span id="banglaDate"></span>
            <span id="digitalClock" class="font-weight-bold"></span>
        </span>
      </li>
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto align-items-center">
      <li class="nav-item">
        <a class="nav-link topbar-icon-btn theme-toggle" href="#" data-theme-toggle title="Switch theme"><i class="fas fa-moon"></i></a>
      </li>
      <li class="nav-item">
        <a class="nav-link topbar-icon-btn" id="voice-btn" href="#" title="Voice search"><i class="fas fa-microphone"></i></a>
      </li>
      @php
          $me = Auth::user();
          $initials = collect(preg_split('/\s+/', trim($me->name ?? 'U')))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
      @endphp
      <li class="nav-item dropdown ml-1">
        <a class="nav-link user-chip" data-toggle="dropdown" href="#" aria-expanded="false">
          <span class="avatar">{{ $initials ?: 'U' }}</span>
          <span class="who d-none d-sm-block">
            <strong>{{ $me->name ?? '' }}</strong>
            <small>{{ config("roles.{$me->role}.label", $me->role) }}</small>
          </span>
          <i class="fas fa-chevron-down text-muted small d-none d-sm-inline"></i>
        </a>
        <div class="dropdown-menu dropdown-menu-right">
          <span class="dropdown-item-text small text-muted px-3">{{ $me->email ?? '' }}</span>
          <div class="dropdown-divider"></div>
          <a href="{{ route('profile.change.password') }}" class="dropdown-item"><i class="fas fa-lock"></i> Change password</a>
          <a href="#" class="dropdown-item text-danger" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
            <i class="fas fa-sign-out-alt text-danger"></i> Logout
          </a>
        </div>
      </li>
    </ul>
  </nav>
  <!-- /.navbar -->

  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">

   @php
    $user = auth()->user();
    $url = route($user->homeRoute());   // protiti role er nijer dashboard
@endphp

<a href="{{ $url }}" class="brand-link1">
    @if(isset($setting) && $setting->logo)
        <img src="{{ asset('back-end/setting/'.$setting->logo) }}" alt="Logo" class="brand-image">
    @else
        <span class="brand-mark">
            <span class="logo-chip"><i class="fas fa-mobile-alt"></i></span>
            <span class="brand-name">{{ $setting->name ?? config('app.name') }}</span>
        </span>
    @endif
</a>




    <!-- Sidebar -->
    <div class="sidebar">
      @include('back-end.includes.navbar')
    </div>
    <!-- /.sidebar -->
  </aside>

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">

    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0">
                @yield('name')
             </h1>
          </div>

        <!--   <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="#">Dashboard</a></li>
              <li class="breadcrumb-item active">@yield('title')</li>
            </ol>
          </div> -->

        </div>
      </div>
    </div>
   

    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">

       @yield('admin-content')

      </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->
  <footer class="main-footer">

  @if(!empty($setting->copyright_text))
      <strong>{{ $setting->copyright_text }}</strong>
  @else
      <strong>Copyright &copy; {{ date('Y') }} Abu Kahar Software.</strong>
      All rights reserved.
  @endif

    
     
    <div class="float-right d-none d-sm-inline-block">
    Developed By 
    @if(!empty($setting->developed_by))
        <b><a href="#" target="_blank">{{ $setting->developed_by }}</a></b>
    @else
        <b><a href="#" target="_blank">Abu Kahar Siddiq</a></b>
    @endif
</div>


  </footer>

  <!-- Control Sidebar -->
  <aside class="control-sidebar control-sidebar-dark">
    <!-- Control sidebar content goes here -->
  </aside>
  <!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->

<!-- jQuery -->
<script src="{{ asset('/') }}back-end/plugins/jquery/jquery.min.js"></script>
<!-- jQuery UI 1.11.4 -->
<script src="{{ asset('/') }}back-end/plugins/jquery-ui/jquery-ui.min.js"></script>
<!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
<script>
  $.widget.bridge('uibutton', $.ui.button)
</script>
<!-- Bootstrap 4 -->
<script src="{{ asset('/') }}back-end/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- Select2 -->
<script src="{{ asset('/') }}back-end/plugins/select2/js/select2.full.min.js"></script>
<!-- ChartJS -->
<!-- <script src="{{ asset('/') }}back-end/plugins/chart.js/Chart.min.js"></script> -->
<!-- <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script> -->
 <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
 <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
<!-- Sparkline -->
<script src="{{ asset('/') }}back-end/plugins/sparklines/sparkline.js"></script>

<!-- daterangepicker -->
<script src="{{ asset('/') }}back-end/plugins/moment/moment.min.js"></script>
<script src="{{ asset('/') }}back-end/plugins/daterangepicker/daterangepicker.js"></script>
<!-- Tempusdominus Bootstrap 4 -->
<script src="{{ asset('/') }}back-end/plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
<!-- Summernote -->

<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>

<!-- overlayScrollbars -->
<script src="{{ asset('/') }}back-end/plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<!-- AdminLTE App -->
<script src="{{ asset('/') }}back-end/dist/js/adminlte.js"></script>

<script src="{{ asset('/') }}back-end/dist/js/pages/dashboard.js"></script>
 <script src="{{ asset('/') }}back-end/assets/extra-libs/DataTables/datatables.min.js"></script>

<!-- Buttons Extensions -->
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>

<!-- Dependencies for export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.68/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.68/vfs_fonts.js"></script>


 <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

 <script src="{{ asset('/') }}back-end/dist/js/jodit.min.js"></script>
 <script src="{{ asset('/') }}back-end/dist/js/spectrum.js"></script>
 <script src="{{ asset('/') }}back-end/dist/js/spartan-multi-image-picker-min.js"></script>

 <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

 <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/timepicker/1.3.5/jquery.timepicker.min.js"></script> -->
 <!-- Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>




<script>
   $(".editor").each(function (el) {
        var $this = $(this);
        var buttons = $this.data("buttons");
        buttons = !buttons
            ? "bold,underline,italic,hr,|,ul,ol,|,align,paragraph,|,image,table,link,undo,redo"
            : buttons;

        var editor = new Jodit(this, {
            uploader: {
                insertImageAsBase64URI: true,
            },
            toolbarAdaptive: false,
            defaultMode: "1",
            toolbarSticky: false,
            showXPathInStatusbar: false,
            buttons: buttons,
        });
    });
</script>

 <script>
    $(document).ready(function() {
        $('#summernote').summernote({
            height: 100,
            minHeight: null, 
            maxHeight: null,
            focus: true
        });
    });
</script>

<!-- Include Toastr JS library -->
<script>
  
    function confirmDelete(id) {
        Swal.fire({
            title: 'Are you sure?',
            text: "Delete this item!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes',
            cancelButtonText: 'No',
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deleteForm-' + id).submit();
            }
        })
    }


</script>

  {!! Toastr::message() !!}

  {{-- controller er back()->with('error'|'success', ...) message dekhano.
       toastr HTML render kore, ar message e user er input (IMEI, naam) thakte pare, tai e() diye escape --}}
  @if (session('error'))
    <script>toastr.error(@json(e(session('error'))), 'Error', { timeOut: 10000 });</script>
  @endif
  @if (session('success'))
    <script>toastr.success(@json(e(session('success'))));</script>
  @endif
  @if ($errors->any())
    <script>toastr.error(@json(implode('<br>', array_map('e', $errors->all()))), 'Please fix the following', { timeOut: 10000 });</script>
  @endif

    <script>
      // $("#zero_config").DataTable();

       $('.select2').select2()

      $('.select2bs4').select2({
        theme: 'bootstrap4'
      });

    </script>


     <script>
      $(document).ready(function () {
          $('.zero_config').DataTable({});
      });
    </script>

    <script>
    document.addEventListener("DOMContentLoaded", function(){
        let date = new Date();
        let day = String(date.getDate()).padStart(2, '0');
        let month = String(date.getMonth() + 1).padStart(2, '0');
        let year = date.getFullYear();
        let formattedDate = day + '-' + month + '-' + year;

        document.getElementById('dateInput').value = formattedDate;
    });
</script>

 <script>
    document.addEventListener("DOMContentLoaded", function(){
        let date = new Date();
        let day = String(date.getDate()).padStart(2, '0');
        let month = String(date.getMonth() + 1).padStart(2, '0');
        let year = date.getFullYear();
        let formattedDate = day + '-' + month + '-' + year;

        document.getElementById('dateInput1').value = formattedDate;
    });
</script>

<script>
$(document).ready(function() {
    function getBanglaNumber(number) {
        const banglaNumbers = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
        return number.toString().split('').map(char => {
            return banglaNumbers[char] || char;
        }).join('');
    }

    function updateBanglaDate() {
        const date = new Date();
        
        const banglaDay = {
            'Sunday': 'রবিবার',
            'Monday': 'সোমবার', 
            'Tuesday': 'মঙ্গলবার',
            'Wednesday': 'বুধবার',
            'Thursday': 'বৃহস্পতিবার',
            'Friday': 'শুক্রবার',
            'Saturday': 'শনিবার'
        };

        const banglaMonth = {
            'January': 'জানুয়ারি',
            'February': 'ফেব্রুয়ারি',
            'March': 'মার্চ', 
            'April': 'এপ্রিল',
            'May': 'মে',
            'June': 'জুন',
            'July': 'জুলাই',
            'August': 'আগস্ট',
            'September': 'সেপ্টেম্বর',
            'October': 'অক্টোবর',
            'November': 'নভেম্বর',
            'December': 'ডিসেম্বর'
        };

        const day = banglaDay[date.toLocaleString('en-us', {weekday: 'long'})];
        const month = banglaMonth[date.toLocaleString('en-us', {month: 'long'})];
        const dateNum = getBanglaNumber(date.getDate());
        const year = getBanglaNumber(date.getFullYear());

        const fullDate = `${day}, ${month} ${dateNum}, ${year}`;
        $('#banglaDate').text(fullDate);
    }

    function updateClock() {
        const date = new Date();
        let hours = date.getHours();
        let minutes = date.getMinutes();
        let seconds = date.getSeconds();
        let ampm = hours >= 12 ? 'PM' : 'AM';
        
        // Convert to 12-hour format
        hours = hours % 12;
        hours = hours ? hours : 12; // the hour '0' should be '12'
        
        // Add leading zeros
        hours = getBanglaNumber(hours.toString().padStart(2, '0'));
        minutes = getBanglaNumber(minutes.toString().padStart(2, '0'));
        seconds = getBanglaNumber(seconds.toString().padStart(2, '0'));

        const time = `${hours}:${minutes}:${seconds} ${ampm}`;
        $('#digitalClock').text(time);
    }

    // Initial update
    updateBanglaDate();
    updateClock();

    // Update date every minute
    setInterval(updateBanglaDate, 60000);
    
    // Update clock every second
    setInterval(updateClock, 1000);
});
</script>


<script>

    function updateStatus(url, status) {

        $.ajax({
            url: url,
            type: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                status: status,
            },
            success: function (response) {
                if (response.success) {
                    toastr.success(response.message || 'Status updated successfully!');
                } else {
                    toastr.error(response.message || 'Failed to update status!');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX Error:', error);
                toastr.error('Something went wrong while updating the status.');
            }
        });
    }

</script>

<script>
    $(document).ready(function() {
        $(document).on('input', '.inputDisableText', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    });

     //Timepicker
    $('#timepicker').datetimepicker({
      format: 'LT'
    })

    $('#timepickerout').datetimepicker({
        format: 'LT'
    });


</script>

<script>
    $('#image').on('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#previewImg').attr('src', e.target.result);
                $('#imagePreview').show();
            };
            reader.readAsDataURL(file);
        } else {
            $('#imagePreview').hide();
        }
    });
 </script>

 <script>
    const voiceBtn = document.getElementById('voice-btn');
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (SpeechRecognition) {
        const recognition = new SpeechRecognition();
        recognition.lang = 'en-US';

        const voiceCommands = {
            "dashboard": "{{ route('admin.dashboard') }}",
            "product": "{{ route('product.index') }}",
            "add product": "{{ route('product.create') }}",
            "purchase list": "{{ route('purchase.index') }}",
            "add purchase": "{{ route('purchase.create') }}",
            "sale list": "{{ route('sale.index') }}",
            "add sale": "{{ route('sale.create') }}",

            "expense list": "{{ route('expense.index') }}",
            "add expense": "{{ route('expense.create') }}",

            "stock report": "{{ route('reports.stock') }}",
            "profit": "{{ route('reports.net-profit') }}",
            "sale summary": "{{ route('reports.sales_summary') }}",

            "settings": "{{ route('setting.create') }}",
        };

        voiceBtn.addEventListener('click', () => {
            recognition.start();
            voiceBtn.innerText = "Listening...";
        });

        recognition.onresult = (event) => {
            const result = event.results[0][0].transcript.toLowerCase();
            voiceBtn.innerText = "Please Command";
            console.log("Input:", result);

            let found = false;
            
            for (const [key, url] of Object.entries(voiceCommands)) {
                if (result.includes(key)) {
                    window.location.href = url;
                    found = true;
                    break;
                }
            }

            if (!found) {
                speakNotification("Sorry, command not found");
            }
        };

        function speakNotification(text) {
            const speech = new SpeechSynthesisUtterance(text);
            window.speechSynthesis.speak(speech);
        }

        recognition.onerror = () => {
            voiceBtn.innerText = "Please Command";
        };

    } else {
        console.error("Speech Recognition not supported in this browser.");
    }
</script>


@stack('admin-scripts')
</body>
</html>
