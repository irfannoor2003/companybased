{{--
    Compatibility view for the payslip PDF.

    The payslip used to be a standalone HTML document that borrowed only the
    invoice's colour and stylesheet. It now renders through the shared document
    frame like everything else; this view remains for callers that render it
    directly (and for the view-layer styling test) and simply builds the same
    payload the controller builds.
--}}
@include('documents.pdf', \App\Support\PayslipDocument::payload($run, $payslip))
