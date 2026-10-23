@if($status === 'approved')
    <span class="badge badge-success">Approved</span>
@elseif($status === 'rejected')
    <span class="badge badge-danger">Rejected</span>
@else
    <span class="badge badge-warning">Pending approval</span>
@endif
