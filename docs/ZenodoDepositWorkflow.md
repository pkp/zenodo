# Zenodo Deposit Workflow Diagram

This diagram outlines the workflow followed for depositing a record to Zenodo once it has been selected.

If DOI versioning is enabled in OJS, the workflow remains the same, but the user can deposit each major version of a record.

The workflow does not include cases where errors are encountered during the process.

```mermaid
flowchart TD
    Start([Deposit Selected Records]) --> Queue[Queue One Job per Record<br/>Set Status: SUBMITTED]
    Queue --> JobRuns([Job Runs]) --> CheckAPIKey{API Key<br/>Configured?}

    CheckAPIKey -->|No| ErrorNoKey[Return Error:<br/>No API Key]
    CheckAPIKey -->|Yes| Preflight{Preflight:<br/>title, authors, date,<br/>PDF galley, DOI or<br/>Zenodo DOIs enabled?}

    Preflight -->|Missing| ErrorPreflight[Set Status: FAILED<br/>with the reason]
    Preflight -->|Complete| CheckExisting{Existing<br/>Zenodo ID stored?}

    CheckExisting -->|Yes| CheckPublished{Is Record<br/>published in Zenodo?}
    CheckExisting -->|No| CreateDraft[Create New Draft]

    CheckPublished -->|Deleted in Zenodo<br/>tombstone| CreateDraft
    CheckPublished -->|No - is Draft| UpdateExisting[Update Existing<br/>Draft Metadata]
    CheckPublished -->|Yes| CreateFromPublished[Create Draft from<br/>Published Record]

    UpdateExisting -->|Draft was removed<br/>in Zenodo| CreateDraft
    UpdateExisting -->|Updated| SetZenodoID[Store Zenodo ID<br/>for Object & Siblings]
    CreateFromPublished --> UpdateDraft[Update Draft Metadata]

    CreateDraft --> SetZenodoID
    UpdateDraft --> SetZenodoID

    SetZenodoID --> CheckPublishedForFiles{Is Record<br/>Published?}

    CheckPublishedForFiles -->|Yes| SkipFiles[Skip File Upload<br/>Cannot update files<br/>on published records]
    CheckPublishedForFiles -->|No, existing draft| ReplaceFiles[Delete Draft Files]
    CheckPublishedForFiles -->|No, new draft| DepositFiles[Upload Files from Galleys]
    ReplaceFiles --> DepositFiles

    DepositFiles --> CheckAutoPublish{Auto Publish Enabled<br/>and No Open Review,<br/>or Previously Published?}
    SkipFiles --> CheckAutoPublish

    CheckAutoPublish -->|Yes| PublishDraft[Publish Draft]
    CheckAutoPublish -->|No| SkipPublish[Keep as Draft]

    PublishDraft --> SetRegistered[Set Status:<br/>REGISTERED]
    SkipPublish --> SetRegistered

    SetRegistered --> CheckCommunity{Community ID<br/>Configured?}

    CheckCommunity -->|No| Success[Return Success]
    CheckCommunity -->|Yes| CheckIfPublished{Is Record<br/>Published?}

    CheckIfPublished -->|Yes| SubmitPublished[Submit Published<br/>Record to Community]
    CheckIfPublished -->|No| CheckExistingReview{Open Review<br/>Request Exists?}

    CheckExistingReview -->|No| CreateReview[Create Review Request]
    CheckExistingReview -->|Yes| CheckAutoPublishCommunity{Auto Publish Community<br/>Setting Enabled?}
    CreateReview --> SubmitReview[Submit Review Request]

    SubmitPublished --> CheckAutoPublishCommunity
    SubmitReview --> CheckAutoPublishCommunity

    CheckAutoPublishCommunity -->|Yes| AcceptReview[Accept Review<br/>Publishes record<br />if not already published]
    CheckAutoPublishCommunity -->|No| Success

    AcceptReview --> Success

    ErrorNoKey --> End([End])
    ErrorPreflight --> End
    Success --> End

    style Start fill:#d4edda
    style Success fill:#d4edda
    style End fill:#d4edda
    style ErrorNoKey fill:#f8d7da
    style ErrorPreflight fill:#f8d7da
    style PublishDraft fill:#fff3cd
    style AcceptReview fill:#fff3cd
```
